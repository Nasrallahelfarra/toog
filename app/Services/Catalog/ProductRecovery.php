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
    public function __construct(private readonly Ai $ai, private readonly ProductReview $reviewer, private readonly PublicWebClient $web) {}

    public function run(ProductRecommendation $run): void
    {
        try {
            $store = $run->store;
            if (! $this->ai->isLive() || $store->aiBudgetExceeded()) {
                $run->update(['status' => 'failed', 'error' => $this->ai->isLive() ? 'budget_exceeded' : 'not_configured']);

                return;
            }
            $snapshot = $run->snapshot;
            $product = new Product($snapshot);
            $ctx = new StoreContext($store, $run->product_id);
            $result = app(EvaluationEngine::class)->score(Candidate::fromProduct($product), $ctx);
            $evaluation = new Evaluation(['store_id' => $store->id, 'name' => $snapshot['name'], 'category' => $snapshot['category'] ?? null]);
            $context = $this->reviewer->context($evaluation, $ctx, $result);
            $context['product_performance'] = $snapshot;
            $context['history_window'] = 'Classification aggregates use up to 12 months of imported sales. Zero views may mean missing tracking.';
            $image = $this->image($snapshot['image_url'] ?? null);
            if ($image) {
                $path = "product-recommendations/{$store->id}/".Str::uuid().'.'.$image['extension'];
                if (! Storage::disk('public')->put($path, $image['bytes'])) {
                    throw new \RuntimeException('Could not preserve the product reference image.');
                }
                $run->update(['source_image_path' => $path]);
            }
            $answer = $this->ai->json(
                operation: 'product_recovery', store: $store,
                system: 'You are a product recovery specialist. Diagnose a slow-moving catalog product using its actual sales, stock, returns, price and store evidence. Recommend practical actions for this particular product. Explain likely causes as hypotheses, not proven causes. Do not assume redesign is necessary. When an observed visual issue warrants redesign, offer a precise visual instruction. Business actions should include a concrete next step and how to assess it.'.$this->reviewer->instructions(),
                prompt: json_encode($context, JSON_UNESCAPED_UNICODE)."\nImage available: ".($image ? 'yes' : 'no'),
                schema: $this->reviewer->schema(), fallback: fn () => [],
                images: $image ? [['mime' => $image['mime'], 'data' => base64_encode($image['bytes'])]] : [], userId: $run->user_id,
            );
            $review = $this->reviewer->normalize($answer->data, $answer->fromModel, (bool) $image, $context);
            $run->update(['review' => $review, 'status' => $review['status'] === 'available' ? 'completed' : 'failed',
                'error' => $review['status'] === 'available' ? null : 'review_failed']);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => 'review_failed']);
        }
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
