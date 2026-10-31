<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\Product;

/**
 * The comparable shape of a product: what the engine scores. Built from
 * an evaluation (a candidate) or from a catalog product.
 */
final readonly class Candidate
{
    /**
     * @param  list<string>  $colors
     */
    public function __construct(
        public ?string $category,
        public ?float $price,
        public array $colors = [],
        public ?string $pattern = null,
        public ?string $material = null,
        public ?string $style = null,
        public ?string $season = null,
    ) {}

    public static function fromEvaluation(Evaluation $e): self
    {
        return new self(
            $e->category,
            $e->price !== null ? (float) $e->price : null,
            array_values($e->colors ?? []),
            $e->pattern,
            $e->material,
            $e->style,
            $e->season,
        );
    }

    public static function fromProduct(Product $p): self
    {
        $f = $p->features ?? [];

        return new self(
            $p->category,
            $p->price !== null ? (float) $p->price : null,
            array_values($f['colors'] ?? []),
            $f['pattern'] ?? null,
            $f['material'] ?? null,
            $f['style'] ?? null,
            $f['season'] ?? null,
        );
    }

    /**
     * A copy with some fields changed — used to simulate a modification.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            $changes['category'] ?? $this->category,
            array_key_exists('price', $changes) ? (float) $changes['price'] : $this->price,
            $changes['colors'] ?? $this->colors,
            $changes['pattern'] ?? $this->pattern,
            $changes['material'] ?? $this->material,
            $changes['style'] ?? $this->style,
            $changes['season'] ?? $this->season,
        );
    }

    /**
     * Similarity in [0, 1] across the attributes that drive sales.
     */
    public function similarity(self $other): float
    {
        $score = 0.0;

        $a = $this->colors;
        $b = $other->colors;
        if ($a && $b) {
            $score += 0.35 * count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
        }

        $score += 0.20 * (int) ($this->pattern && $this->pattern === $other->pattern);
        $score += 0.15 * (int) ($this->material && $this->material === $other->material);
        $score += 0.15 * (int) ($this->style && $this->style === $other->style);
        $score += 0.10 * (int) ($this->season && $this->season === $other->season);

        if ($this->price && $other->price) {
            $score += 0.05 * (1 - abs($this->price - $other->price) / max($this->price, $other->price));
        }

        return $score;
    }
}
