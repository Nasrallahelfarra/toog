<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\Store;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entry point for every AI call in the platform.
 *
 * It picks the provider, enforces each store's monthly AI budget, records
 * tokens and cost in ai_usages (the admin cost dashboard reads from there),
 * and falls back to deterministic template output when the model is
 * unavailable — so a Gemini outage never blocks an evaluation.
 */
class Ai
{
    private ?AiProvider $provider = null;

    public function provider(): AiProvider
    {
        if ($this->provider) {
            return $this->provider;
        }

        $config = config('mojo.ai.gemini');

        return $this->provider = (config('mojo.ai.provider') === 'gemini' && filled($config['api_key']))
            ? new GeminiProvider($config)
            : new FakeProvider;
    }

    public function isLive(): bool
    {
        return ! $this->provider() instanceof FakeProvider;
    }

    /**
     * Structured JSON call. Returns the model's answer, or $fallback()'s
     * output when running in demo mode, over budget, or on failure.
     *
     * @param  array<string, mixed>  $schema
     * @param  Closure(): array<string, mixed>  $fallback
     * @param  list<array{mime: string, data: string}>  $images
     */
    public function json(
        string $operation,
        ?Store $store,
        string $system,
        string $prompt,
        array $schema,
        Closure $fallback,
        array $images = [],
        ?int $userId = null,
        ?int $timeoutSeconds = null,
    ): AiJsonResult {
        $provider = $this->provider();
        if ($provider instanceof GeminiProvider && $timeoutSeconds !== null) {
            $provider = $provider->withTimeout($timeoutSeconds);
        }

        if (! $this->isLive()) {
            $this->record($operation, $store, $userId, $provider->name(), 'fake', meta: ['mode' => 'demo']);

            return new AiJsonResult($fallback(), false, 0.0);
        }

        if ($store?->aiBudgetExceeded()) {
            $this->record($operation, $store, $userId, $provider->name(), $provider->textModel(), error: 'budget_exceeded');

            return new AiJsonResult($fallback(), false, 0.0);
        }

        $started = microtime(true);

        try {
            $response = $provider->json($system, $prompt, $schema, $images);
            $cost = $this->cost($response->model, $response->inputTokens, $response->outputTokens);

            $this->record($operation, $store, $userId, $provider->name(), $response->model,
                in: $response->inputTokens, out: $response->outputTokens, cost: $cost, started: $started);

            return new AiJsonResult($response->data, true, $cost);
        } catch (Throwable $e) {
            report($e);
            $this->record($operation, $store, $userId, $provider->name(), $provider->textModel(),
                error: Str::limit($e->getMessage(), 250), started: $started);

            return new AiJsonResult($fallback(), false, 0.0);
        }
    }

    /**
     * Generate one image and store it on the public disk.
     *
     * @throws AiException when the store is over budget or the model fails
     */
    public function image(
        string $operation,
        Store $store,
        string $prompt,
        ?string $sourcePath = null,
        string $aspectRatio = '1:1',
        ?int $userId = null,
    ): GeneratedImage {
        $provider = $this->provider();

        if ($this->isLive() && $store->aiBudgetExceeded()) {
            $this->record($operation, $store, $userId, $provider->name(), $provider->imageModel(), error: 'budget_exceeded');

            throw AiException::budgetExceeded();
        }

        $source = null;
        if ($sourcePath) {
            if (! Storage::disk('public')->exists($sourcePath)) {
                throw new AiException('The reference image is missing. Upload it again before editing.');
            }
            $source = [
                'mime' => Storage::disk('public')->mimeType($sourcePath) ?: 'image/png',
                'data' => base64_encode(Storage::disk('public')->get($sourcePath)),
            ];
        }

        $started = microtime(true);

        try {
            $response = $provider->image($prompt, $source, $aspectRatio);
        } catch (Throwable $e) {
            $this->record($operation, $store, $userId, $provider->name(), $provider->imageModel(),
                error: Str::limit($e->getMessage(), 250), started: $started);

            throw $e instanceof AiException ? $e : new AiException($e->getMessage(), previous: $e);
        }

        $cost = $this->cost($response->model, $response->inputTokens, $response->outputTokens, 1);
        $path = "designs/{$store->id}/".Str::uuid().'.'.$response->extension();
        Storage::disk('public')->put($path, $response->bytes);

        $this->record($operation, $store, $userId, $provider->name(), $response->model,
            in: $response->inputTokens, out: $response->outputTokens, images: 1, cost: $cost, started: $started);

        return new GeneratedImage($path, $provider->name(), $response->model, $cost);
    }

    /**
     * USD cost from the pricing table in config/mojo.php.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens, int $images = 0): float
    {
        // Model ids contain dots ("gemini-3.8-flash"), so no dot-notation lookup.
        $price = config('mojo.ai.pricing')[$model] ?? ['input' => 0, 'output' => 0];

        $tokens = ($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000;

        // Image models bill the picture itself per image; the per-image
        // price already covers its output tokens.
        if ($images > 0 && isset($price['image'])) {
            return round($inputTokens * $price['input'] / 1_000_000 + $images * $price['image'], 6);
        }

        return round($tokens, 6);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(
        string $operation,
        ?Store $store,
        ?int $userId,
        string $provider,
        string $model,
        int $in = 0,
        int $out = 0,
        int $images = 0,
        float $cost = 0.0,
        ?string $error = null,
        ?float $started = null,
        array $meta = [],
    ): void {
        AiUsage::query()->create([
            'store_id' => $store?->id,
            'user_id' => $userId ?? auth()->id(),
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'images' => $images,
            'cost' => $cost,
            'success' => $error === null,
            'error' => $error,
            'latency_ms' => $started ? (int) ((microtime(true) - $started) * 1000) : 0,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
