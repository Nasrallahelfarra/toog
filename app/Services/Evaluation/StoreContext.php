<?php

namespace App\Services\Evaluation;

use App\Models\CompetitorProduct;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\Trend;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * Everything the engine needs to know about one store, loaded once and
 * reused across a batch of evaluations.
 */
class StoreContext
{
    /** @var Collection<int, array{name: string, candidate: Candidate}> */
    public Collection $winners;

    /** @var Collection<int, array{name: string, candidate: Candidate}> */
    public Collection $stagnant;

    /** @var Collection<int, array{name: string, candidate: Candidate}> */
    public Collection $allProducts;

    /** @var Collection<int, Candidate> */
    public Collection $recentLaunches;

    /** @var Collection<int, Trend> */
    public Collection $trends;

    /** @var Collection<int, array{category: string|null, candidate: Candidate}> */
    public Collection $competitorProducts;

    /** @var Collection<int, SeasonalEvent> */
    public Collection $upcomingEvents;

    /** @var list<string> */
    public array $saudiPalette;

    /** @var array<string, float> */
    public array $weights;

    public int $acceptThreshold;

    public int $rejectThreshold;

    public function __construct(public readonly Store $store)
    {
        $products = Product::query()->where('store_id', $store->id)->get();

        $this->allProducts = $products->map(fn (Product $p) => ['name' => $p->name, 'candidate' => Candidate::fromProduct($p)]);
        $this->winners = $products->where('performance', Product::WINNER)
            ->map(fn (Product $p) => ['name' => $p->name, 'candidate' => Candidate::fromProduct($p)])->values();
        $this->stagnant = $products->where('performance', Product::STAGNANT)
            ->map(fn (Product $p) => ['name' => $p->name, 'candidate' => Candidate::fromProduct($p)])->values();

        $recentProducts = $products->filter(fn (Product $p) => $p->launched_at?->greaterThan(now()->subDays(90)))
            ->map(fn (Product $p) => Candidate::fromProduct($p));
        $recentApproved = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('final_decision', 'approved')
            ->where('decided_at', '>=', now()->subDays(90))
            ->get()
            ->map(fn (Evaluation $e) => Candidate::fromEvaluation($e));
        $this->recentLaunches = $recentProducts->concat($recentApproved)->values();

        $this->trends = Trend::query()->where('store_id', $store->id)->get();

        $this->competitorProducts = CompetitorProduct::query()->where('store_id', $store->id)->get()
            ->map(function (CompetitorProduct $cp) {
                $f = $cp->features ?? [];

                return [
                    'category' => $cp->category,
                    'candidate' => new Candidate($cp->category, $cp->price ? (float) $cp->price : null,
                        $f['colors'] ?? [], $f['pattern'] ?? null, $f['material'] ?? null, $f['style'] ?? null, $f['season'] ?? null),
                ];
            });

        $this->upcomingEvents = SeasonalEvent::query()->forStore($store)
            ->where('ends_on', '>=', now()->toDateString())
            ->orderBy('starts_on')
            ->get();

        $this->saudiPalette = Vocabulary::saudiPalette();
        $this->weights = $store->weights();
        $this->acceptThreshold = (int) $store->setting('accept_threshold');
        $this->rejectThreshold = (int) $store->setting('reject_threshold');
    }

    /**
     * Products of the same category, or the whole catalog when the
     * category has too few examples to learn from.
     *
     * @param  Collection<int, array{name: string, candidate: Candidate}>  $items
     * @return Collection<int, array{name: string, candidate: Candidate}>
     */
    public function inCategory(Collection $items, ?string $category, int $min = 3): Collection
    {
        $same = $items->filter(fn ($i) => $category && $i['candidate']->category === $category)->values();

        return $same->count() >= $min ? $same : $items;
    }
}
