<?php

namespace App\Services\Ai;

final readonly class AiImageResponse
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
