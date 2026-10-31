<?php

namespace App\Services\Ai;

/**
 * A model provider. Gemini is the production implementation; FakeProvider
 * serves demo output when no API key is configured. Swapping providers
 * later means adding one class that implements this interface.
 */
interface AiProvider
{
    public function name(): string;

    public function textModel(): string;

    public function imageModel(): string;

    /**
     * Ask for a JSON object matching $schema (a JSON Schema array).
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array{mime: string, data: string}>  $images  base64 inline images
     */
    public function json(string $system, string $prompt, array $schema, array $images = []): AiResponse;

    /**
     * Generate one image. $source, when given, is edited instead of
     * generating from scratch (keeps the product's shape — FR-4.2).
     *
     * @param  array{mime: string, data: string}|null  $source
     */
    public function image(string $prompt, ?array $source = null, string $aspectRatio = '1:1'): AiImageResponse;
}
