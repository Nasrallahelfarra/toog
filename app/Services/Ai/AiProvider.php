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
     * @param  list<array{mime: string, data: string, label?: string}>  $images  base64 inline images, each optionally introduced by a label
     */
    public function json(string $system, string $prompt, array $schema, array $images = []): AiResponse;

    /**
     * Generate one image from the prompt and the reference images, in
     * order; a label is sent just before its image so the prompt can name
     * it ("Image 1 — …"). A single unlabelled reference is edited instead
     * of drawing from scratch (keeps the product's shape — FR-4.2).
     *
     * @param  list<array{mime: string, data: string, label?: string}>  $references
     */
    public function image(string $prompt, array $references = [], string $aspectRatio = '1:1'): AiImageResponse;
}
