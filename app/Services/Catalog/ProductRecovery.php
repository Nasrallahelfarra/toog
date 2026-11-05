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
use Illuminate\Support\Facades\Storage;
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
                system: $this->role($snapshot['performance'] ?? null).' Recommend practical actions for this particular product toward the goal in the context. Explain likely causes as hypotheses, not proven causes. Do not assume redesign is necessary. When an observed visual issue warrants redesign and an image is available, provide THREE distinct alternative visual directions: the merchant will choose ONE, not combine them. Each direction must be a complete standalone edit, grounded in the supplied image. Explain its visible change, visual impact and how it may help a shopper understand or trust the product. Consider focal clarity, contrast, visual hierarchy, legibility and honest presentation. Do not promise conversion gains or invent features. Preserve product geometry, construction and brand marks. Business actions should include a concrete next step and how to assess it.'.$this->reviewer->instructions().$this->marketing->instructions(),
                prompt: json_encode($context, JSON_UNESCAPED_UNICODE)."\nImage available: ".($image ? 'yes' : 'no')."\nFor each visual alternative: reason describes the observed issue and intended visual impact; effect explains the plausible benefit for shopper understanding, trust or purchase consideration, as a hypothesis; instruction contains ONLY the exact standalone image edit. Provide three genuinely distinct directions when visual improvement is warranted, before any business actions. Use concise narrative fields without repeating the same explanation.",
                schema: $this->schema(), fallback: fn () => [],
                images: $image ? [['mime' => $image['mime'], 'data' => base64_encode($image['bytes'])]] : [], userId: $run->user_id, timeoutSeconds: 120,
            );
            if (! $progress->advance($run, 'saving')) {
                return;
            }
            $review = $this->reviewer->normalize($answer->data, $answer->fromModel, (bool) $image, $context);
            if ($review['status'] === 'available') {
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
        $schema['properties']['marketing_plan'] = $this->marketing->schema(fn (array $properties) => $this->reviewer->object($properties));
        $schema['required'][] = 'marketing_plan';

        return $schema;
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
