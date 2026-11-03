<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini API over REST (generateContent). JSON output uses
 * responseMimeType + responseJsonSchema; images come back as inlineData.
 */
class GeminiProvider implements AiProvider
{
    /**
     * @param  array{api_key: string, base_url: string, text_model: string, image_model: string, timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    /** Bound this call without changing the shared provider used by other jobs. */
    public function withTimeout(int $seconds): self
    {
        return new self([...$this->config, 'timeout' => max(1, min($this->config['timeout'], $seconds))]);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function textModel(): string
    {
        return $this->config['text_model'];
    }

    public function imageModel(): string
    {
        return $this->config['image_model'];
    }

    public function json(string $system, string $prompt, array $schema, array $images = []): AiResponse
    {
        $parts = [];

        foreach ($images as $image) {
            $parts[] = ['inlineData' => ['mimeType' => $image['mime'], 'data' => $image['data']]];
        }

        $parts[] = ['text' => $prompt];

        $body = $this->generate($this->textModel(), [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $schema,
            ],
        ]);

        $text = collect($body['candidates'][0]['content']['parts'] ?? [])
            ->pluck('text')
            ->filter()
            ->implode('');

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new AiException('Gemini returned a response that is not valid JSON.');
        }

        [$in, $out] = $this->usage($body);

        return new AiResponse($data, $this->textModel(), $in, $out);
    }

    public function image(string $prompt, ?array $source = null, string $aspectRatio = '1:1'): AiImageResponse
    {
        $parts = [];

        if ($source) {
            $parts[] = ['inlineData' => ['mimeType' => $source['mime'], 'data' => $source['data']]];
        }

        $parts[] = ['text' => $prompt];

        $body = $this->generate($this->imageModel(), [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => $aspectRatio],
            ],
        ]);

        $inline = collect($body['candidates'][0]['content']['parts'] ?? [])
            ->map(fn (array $part) => $part['inlineData'] ?? $part['inline_data'] ?? null)
            ->filter()
            ->first();

        if (! $inline) {
            $reason = $body['candidates'][0]['finishReason'] ?? $body['promptFeedback']['blockReason'] ?? 'unknown';

            throw new AiException("Gemini returned no image (reason: {$reason}).");
        }

        [$in, $out] = $this->usage($body);

        return new AiImageResponse(
            base64_decode($inline['data']),
            $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png',
            $this->imageModel(),
            $in,
            $out,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function generate(string $model, array $payload): array
    {
        try {
            return $this->client()
                ->post("models/{$model}:generateContent", $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            $message = $e->response->json('error.message') ?? $e->getMessage();

            throw new AiException("Gemini request failed: {$message}", previous: $e);
        } catch (ConnectionException $e) {
            throw new AiException('Could not reach the Gemini API: '.$e->getMessage(), previous: $e);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->connectTimeout(min(10, $this->config['timeout']))
            ->timeout($this->config['timeout'])
            ->retry(2, 1500, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 503], true), throw: false)
            ->acceptJson();
    }

    /**
     * Thinking tokens are billed as output.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: int}
     */
    private function usage(array $body): array
    {
        $usage = $body['usageMetadata'] ?? [];

        return [
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
        ];
    }
}
