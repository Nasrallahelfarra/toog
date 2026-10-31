<?php

namespace App\Services\Ai;

final readonly class GeneratedImage
{
    public function __construct(
        public string $path,
        public string $provider,
        public string $model,
        public float $cost,
    ) {}
}
