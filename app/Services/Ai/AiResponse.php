<?php

namespace App\Services\Ai;

final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
