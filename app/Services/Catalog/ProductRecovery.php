<?php

namespace App\Services\Catalog;

use App\Models\Evaluation;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Services\Ai\Ai;
use App\Services\Evaluation\Candidate;
use App\Services\Evaluation\EvaluationEngine;
use App\Services\Evaluation\ProductReview;
use App\Services\Evaluation\StoreContext;
use App\Services\Market\PublicWebClient;
use App\Support\Vocabulary;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ProductRecovery
{
    public function __construct(
        private readonly Ai $ai,
        private readonly ProductReview $reviewer,
        private readonly PublicWebClient $web,
        private readonly RecoveryMarketing $marketing,
    ) {}

    public function run(ProductRecommendation $run): void
    {
        $progress = app(RecommendationProgress::class);
        try {
            $store = $run->store;
            if (! $this->ai->isLive() || $store->aiBudgetExceeded()) {
                $progress->fail($run->id, $this->ai->isLive() ? 'budget_exceeded' : 'not_configured');

                return;
            }
            $snapshot = $run->snapshot;
            $product = new Product($snapshot);
            $ctx = new StoreContext($store, $run->product_id);
            $result = app(EvaluationEngine::class)->score(Candidate::fromProduct($product), $ctx);
            $evaluation = new Evaluation(['store_id' => $store->id, 'name' => $snapshot['name'], 'category' => $snapshot['category'] ?? null]);
            $context = $this->reviewer->context($evaluation, $ctx, $result);
            $context['product_performance'] = $snapshot;
            $context['classification_definition'] = 'stagnant means stocked inventory with at least 90 consecutive days without sales covered by sufficient data. at_risk is an early warning from weak relative performance or high stock coverage; it does not prove stagnation. Recommend prevention for at_risk and recovery for stagnant. The class is a computed signal, not a proven cause of customer behavior.';
            $context['history_window'] = 'Classification aggregates use up to 12 months of imported sales. Zero views may mean missing tracking.';
            $context['market_context'] = $this->marketing->context($store, $snapshot, $ctx);
            $context['goal'] = $this->goal($snapshot['performance'] ?? null);
            if (! $progress->advance($run, 'image')) {
                return;
            }
            $image = $this->image($snapshot['image_url'] ?? null);
            if ($image) {
                $path = "product-recommendations/{$store->id}/".Str::uuid().'.'.$image['extension'];
                if (! Storage::disk('public')->put($path, $image['bytes'])) {
                    throw new \RuntimeException('Could not preserve the product reference image.');
                }
                if (! ProductRecommendation::query()->whereKey($run->id)->where('status', 'processing')->update(['source_image_path' => $path])) {
                    Storage::disk('public')->delete($path);

                    return;
                }
            }
            if (! $progress->advance($run, 'model')) {
                return;
            }
            $answer = $this->ai->json(
                operation: 'product_recovery', store: $store,
                system: $this->role($snapshot['performance'] ?? null).' Recommend practical actions for this particular product toward the goal in the context. Explain likely causes as hypotheses, not proven causes. '.$this->designBrief($snapshot['performance'] ?? null).' Business actions should include a concrete next step and how to assess it.'.$this->reviewer->instructions().$this->marketing->instructions(),
                prompt: json_encode($context, JSON_UNESCAPED_UNICODE)."\nImage available: ".($image ? 'yes' : 'no')."\nAlso return designs: one spec per new design (kind=visual), in the same order as those recommendations, describing what the new design WILL look like, not the current photo: cut is the new cut and silhouette (shape, length and hem, waist and fit, sleeves, neckline); style is the allowed style key the new design follows; pattern is the allowed pattern key of the new design; colors are one to four colour keys of the new design, chosen only from: ".implode(', ', array_keys(Vocabulary::colors()))."; details are the new finishing touches (embroidery, embellishment, trims, layers, closures). Business actions get no spec; with no image, designs is empty. For each new design: title names the new design in a few words; reason says what in the current design holds it back or which selling signal the new design follows (category best sellers, occasions, the product's own sales and returns) and what changes; effect explains why it may look more beautiful and be more likely to catch a shopper's eye and sell, phrased as a hypothesis for shopper understanding, trust or purchase consideration; instruction contains ONLY the exact standalone change to the product's design that matches its design spec (cut, style, colours, pattern and details to apply), never a change to the photo or the model. When an image is available, give exactly three genuinely distinct new designs first, then at most three business actions. Use concise narrative fields without repeating the same explanation.",
                schema: $this->schema(), fallback: fn () => [],
                images: $image ? [['mime' => $image['mime'], 'data' => base64_encode($image['bytes'])]] : [], userId: $run->user_id, timeoutSeconds: 120,
            );
            if (! $progress->advance($run, 'saving')) {
                return;
            }
            $review = $this->reviewer->normalize($answer->data, $answer->fromModel, (bool) $image, $context);
            if ($review['status'] === 'available') {
                $review['recommendations'] = $this->withDesigns($review['recommendations'], (array) ($answer->data['designs'] ?? []), (bool) $image);
                $review['marketing_plan'] = $this->marketing->normalize($answer->data['marketing_plan'] ?? null);
            }
            ProductRecommendation::query()->whereKey($run->id)->where('status', 'processing')->update([
                'review' => json_encode([...$review, '_progress' => $run->review['_progress'] ?? []], JSON_THROW_ON_ERROR),
                'status' => $review['status'] === 'available' ? 'completed' : 'failed',
                'error' => $review['status'] === 'available' ? null : 'review_failed']);
        } catch (Throwable $e) {
            report($e);
            $progress->fail($run->id, 'review_failed');
        }
    }

    /**
     * Slow movers get a recovery specialist; every other product a growth
     * specialist, so best sellers are not treated as problems.
     */
    private function role(?string $class): string
    {
        return in_array($class, [Product::STAGNANT, Product::AT_RISK], true)
            ? 'You are a product recovery specialist. Diagnose a slow-moving catalog product using its actual sales, stock, returns, price and store evidence.'
            : 'You are a product growth specialist. Review a catalog product using its actual sales, stock, returns, price and store evidence.';
    }

    /**
     * New designs are always part of the review when the product can be
     * seen: the merchant wants fresh, more sellable versions of the product,
     * not only a fix for an observed flaw.
     */
    private function designBrief(?string $class): string
    {
        $purpose = $class === Product::WINNER
            ? 'This product already sells well: the new designs are extra variants to extend the line beside the original, never replacements of it.'
            : 'They are new versions of this product that could look more beautiful and be more likely to sell than the current one.';

        return 'When an image is available, ALWAYS propose THREE distinct new design directions for the product itself (for apparel, the garment, e.g. the dress), even when the current design has no clear flaw; this review requires them regardless of any general rule that recommendations are optional. '.$purpose.' The merchant will choose ONE, not combine them. Each direction must be a complete standalone redesign of the product seen in the supplied image: keep what already works and change its form and structure: silhouette and cut, neckline, sleeves, length and hem, waist and fit lines, layers, closures, colour, pattern/print, embroidery, embellishment and trims. Ground each direction in the evidence: what sells in the category (market_context.category_best_sellers colours, patterns and styles, and the best sellers price), the product\'s own sales and returns, upcoming occasions, and what is observed in the image; state which signal it follows, as a hypothesis. Make the three genuinely different, for example one refined version close to the original, one following what sells in the category, and one bolder occasion piece. Never suggest changes to the photo or the model (background, lighting, framing, camera angle, retouching, model, pose, styling, props or scene). Explain the design change, how it changes the look and why a shopper may find it more appealing or trust it more. Keep each design wearable, modest for the Saudi audience and realistic to produce. Do not promise conversion gains or invent features. Preserve brand marks.';
    }

    /** What the recommendation should achieve for the product's class. */
    private function goal(?string $class): string
    {
        return match ($class) {
            Product::STAGNANT => 'recover: revive demand and release the stock tied up in this product',
            Product::AT_RISK => 'prevent: keep this product from becoming a slow mover',
            Product::AVERAGE => 'lift: move this product toward the store best sellers',
            Product::WINNER => 'grow and protect: sell more of this best seller and plan restocking without cutting its margin; avoid discounts unless clearly justified',
            default => 'launch: earn the first steady sales for a product with too little sales history to classify',
        };
    }

    /**
     * The specialist review schema plus the marketing plan.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $schema = $this->reviewer->schema();
        $pair = $this->reviewer->object(['ar' => ['type' => 'string'], 'en' => ['type' => 'string']]);
        // Gemini caps schema complexity and answers 400 INVALID_ARGUMENT past
        // it. Any extra nested field inside each recommendation crosses that
        // cap, and so does a colour enum inside an array, so the design specs
        // are a separate top-level list with plain colour strings: the prompt
        // names the allowed colours and design() drops anything else. Check a
        // change here against the live API, not only the tests.
        $schema['properties']['designs'] = ['type' => 'array', 'maxItems' => 3, 'items' => $this->reviewer->object([
            'cut' => $pair,
            'style' => ['type' => ['string', 'null'], 'enum' => [...array_keys(Vocabulary::styles()), null]],
            'pattern' => ['type' => ['string', 'null'], 'enum' => [...array_keys(Vocabulary::patterns()), null]],
            'colors' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string']],
            'details' => $pair,
        ])];
        $schema['required'][] = 'designs';
        $schema['properties']['marketing_plan'] = $this->marketing->schema(fn (array $properties) => $this->reviewer->object($properties));
        $schema['required'][] = 'marketing_plan';

        return $schema;
    }

    /**
     * Attach each new design's spec (cut, style, pattern, colours, details)
     * to its recommendation: the n-th spec describes the n-th visual
     * recommendation, so the saved shape is one spec per design.
     *
     * @param  list<array<string, mixed>>  $recommendations
     * @param  array<int, mixed>  $specs
     * @return list<array<string, mixed>>
     */
    private function withDesigns(array $recommendations, array $specs, bool $hasImage): array
    {
        $specs = array_values($specs);
        $n = 0;

        return array_map(function (array $item) use ($specs, $hasImage, &$n) {
            $visual = $hasImage && $item['kind'] === 'visual';

            return [...$item, 'design' => $visual ? $this->design($specs[$n++] ?? null) : null];
        }, $recommendations);
    }

    /** @return array<string, mixed>|null */
    private function design(mixed $design): ?array
    {
        $rules = ['cut.ar' => 'required|string|max:800', 'cut.en' => 'required|string|max:800',
            'details.ar' => 'required|string|max:800', 'details.en' => 'required|string|max:800'];
        if (! is_array($design) || Validator::make($design, $rules)->fails()) {
            return null;
        }
        $style = $design['style'] ?? null;
        $pattern = $design['pattern'] ?? null;

        return [
            'cut' => Arr::only($design['cut'], ['ar', 'en']),
            'style' => is_string($style) && array_key_exists($style, Vocabulary::styles()) ? $style : null,
            'pattern' => is_string($pattern) && array_key_exists($pattern, Vocabulary::patterns()) ? $pattern : null,
            'colors' => array_slice(array_values(array_unique(array_intersect(array_filter((array) ($design['colors'] ?? []), 'is_string'), array_keys(Vocabulary::colors())))), 0, 4),
            'details' => Arr::only($design['details'], ['ar', 'en']),
        ];
    }

    /** @return array{bytes: string, mime: string, extension: string}|null */
    private function image(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        try {
            $disk = Storage::disk('public');
            $prefix = rtrim($disk->url(''), '/').'/';
            if (str_starts_with($url, $prefix)) {
                $path = rawurldecode(substr($url, strlen($prefix)));
                if (str_contains($path, '..') || str_contains($path, '\\') || ! $disk->exists($path) || $disk->size($path) > 5_000_000) {
                    return null;
                }
                $bytes = $disk->get($path);
            } else {
                $response = $this->web->get($url);
                if (! $response->successful()) {
                    return null;
                }
                $bytes = $response->body();
            }
            $info = @getimagesizefromstring($bytes);
            if (! $info) {
                return null;
            }
            $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']] ?? null;

            return $extension ? ['bytes' => $bytes, 'mime' => $info['mime'], 'extension' => $extension] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
