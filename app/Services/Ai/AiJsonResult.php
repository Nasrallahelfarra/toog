<?php

namespace App\Services\Ai;

final readonly class AiJsonResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $fromModel  false when the template fallback answered
     */
    public function __construct(
        public array $data,
        public bool $fromModel,
        public float $cost,
    ) {}
}
