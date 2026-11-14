<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\Trend;
use App\Notifications\PlatformNotification;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * Recomputes the store's market trends (FR-2.5 – FR-2.7) from what the
 * active competitors list and post: how fast a colour / pattern / style /
 * material / season is growing (momentum), how widespread it already is
 * (saturation), and whether the store already carries it (gap).
 *
 * Only real scans count once the store has them (seeded samples are
 * dropped and the old trends reset), clothing only in a fashion store.
 * Growth compares what competitors add now (new arrivals, recent posts)
 * with what they already carry, so a first crawl is a baseline, not a
 * surge. Posts weigh by engagement against their account's usual.
 *
 * Existing trends are smoothed rather than replaced, so one noisy scan
 * cannot flip a trend; a trend with no signals left cools and is finally
 * removed. A new rising trend triggers a notification.
 */
class TrendDetector
{
    /** feature key => [trend type, vocabulary group] */
    private const FIELDS = [
        'colors' => ['color', 'colors'],
        'pattern' => ['pattern', 'patterns'],
        'style' => ['style', 'styles'],
        'material' => ['material', 'materials'],
        'season' => ['theme', 'seasons'],
    ];

    /** Baseline values that are never a "trend" on their own. */
    private const NEUTRAL = ['all_year', 'solid'];

    public function __construct(
        private readonly FeatureExtractor $features,
        private readonly CompetitorSignals $competitorSignals,
    ) {}

    /**
     * @return array{updated: int, created: list<string>}
     */
    public function detect(Store $store): array
    {
        $run = SyncRun::query()->create(['store_id' => $store->id, 'type' => 'trend_analysis', 'status' => 'running', 'started_at' => now()]);

        $this->features->enrich($store->id);

        $active = Competitor::query()->where('store_id', $store->id)->where('is_active', true)->pluck('id')->all();
        $live = MarketTrends::hasLiveData($store, $active);
        $catalog = Product::query()->where('store_id', $store->id)->get();

        // The first analysis on real scans starts clean: trends built from
        // sample data say nothing about this store's market.
        if ($live && ! $this->ranOnLiveData($store)) {
            Trend::query()->where('store_id', $store->id)->delete();
        }

        $signals = $this->signals($store, $active, $live, MarketTrends::isFashion($catalog));
        $stats = $this->aggregate($signals);
        $productTotal = max(1, $signals->where('product', true)->count());

        $existing = Trend::query()->where('store_id', $store->id)->get()->keyBy(fn (Trend $t) => "{$t->type}|{$t->value}");
        $seen = [];
        $updated = 0;
        $created = [];

        $totals = $this->typeTotals($stats);

        foreach ($stats as $key => $s) {
            [$type, $value] = explode('|', $key);
            $group = collect(self::FIELDS)->firstWhere(0, $type)[1];
            $label = (Vocabulary::forFrontend()[$group] ?? [])[$value] ?? null;

            if (! $label || in_array($value, self::NEUTRAL, true)) {
                continue;
            }
            $seen[$key] = true;

            // Growth compares the value's share of its type in the last 30
            // days with its share in the 60 days before, so overall market
            // activity (or a fresh monitoring start) does not read as growth.
            $shareRecent = $s['recent'] / max(0.0001, $totals[$type]['recent']);
            $shareOlder = $s['older'] / max(0.0001, $totals[$type]['older']);
            $growth = $shareOlder > 0 ? ($shareRecent - $shareOlder) / $shareOlder * 100 : ($shareRecent >= 0.05 ? 100 : 0);
            // Little history means little confidence.
            $growth *= min(1, $totals[$type]['older'] / 8);
            $growth = max(-80, min(150, $growth));

            $momentum = (int) round(max(0, min(100, 25 + $shareRecent * 150 + max(-50, min(100, $growth)) * 0.3)));
            $saturation = (int) round(max(0, min(100, $s['products'] / $productTotal * 180)));

            $trend = $existing->get($key);

            if ($trend) {
                $blendedGrowth = round((float) $trend->growth * 0.6 + $growth * 0.4, 1);
                $trend->update([
                    'momentum' => (int) round($trend->momentum * 0.6 + $momentum * 0.4),
                    'saturation' => (int) round($trend->saturation * 0.6 + $saturation * 0.4),
                    'growth' => $blendedGrowth,
                    'status' => $this->status($blendedGrowth),
                    'platforms' => array_values(array_unique([...($trend->platforms ?? []), ...$s['platforms']])),
                ]);
                $updated++;

                continue;
            }

            // Only promote a brand-new trend on real evidence: enough
            // sightings, clear growth, momentum, and not already everywhere.
            if ($s['count'] >= 4 && $growth >= 15 && $momentum >= 55 && $saturation < 70) {
                $trend = Trend::query()->create([
                    'store_id' => $store->id, 'type' => $type, 'value' => $value,
                    'name_ar' => $label['ar'], 'name_en' => $label['en'],
                    'momentum' => $momentum, 'growth' => round($growth, 1), 'saturation' => $saturation,
                    'status' => 'rising', 'platforms' => $s['platforms'], 'detected_at' => now(),
                ]);
                $created[] = $trend;
            }
        }

        // A trend with no signals left cools down, and goes once it is cold.
        foreach ($existing->except(array_keys($seen)) as $trend) {
            $momentum = (int) round($trend->momentum * 0.7);
            if ($momentum < 15) {
                $trend->delete();

                continue;
            }
            $growth = max(-80, round(min((float) $trend->growth, 0) - 10, 1));
            $trend->update(['momentum' => $momentum, 'growth' => $growth, 'status' => $this->status($growth)]);
        }

        // Gap analysis (FR-2.6): does the store already carry each trend?
        foreach (Trend::query()->where('store_id', $store->id)->get() as $trend) {
            $trend->update(['in_catalog' => $catalog->contains(fn (Product $p) => $this->productHas($p, $trend))]);
        }

        foreach ($created as $trend) {
            $this->notify($store, $trend->fresh());
        }

        $run->update(['status' => 'completed', 'finished_at' => now(), 'stats' => [
            'updated' => $updated, 'created' => count($created), 'signals' => $signals->count(), 'basis' => $live ? 'live' : 'sample',
        ]]);

        return ['updated' => $updated, 'created' => array_map(fn (Trend $t) => $t->name_en, $created)];
    }

    /**
     * Weighted observations. Competitor listings seen in the last 45 days
     * (best sellers count double): a genuinely new arrival is "recent", the
     * rest of the catalog is the baseline. Posts of the last 90 days, by
     * engagement against their own account's usual.
     *
     * @param  list<int>  $active
     * @return Collection<int, array{features: array<string, mixed>, recent: bool, weight: float, platform: string|null, product: bool}>
     */
    private function signals(Store $store, array $active, bool $live, bool $fashion): Collection
    {
        if ($active === []) {
            return collect();
        }

        $rows = CompetitorProduct::query()->with('competitor:id,platform')->where('store_id', $store->id)
            ->whereIn('competitor_id', $active)
            ->when($live, fn ($q) => $q->whereNotNull('evidence'))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))
            ->get();
        $firstSeen = $rows->groupBy('competitor_id')->map(fn (Collection $items) => $items->min('first_seen_at'));

        $products = $rows
            ->filter(fn (CompetitorProduct $p) => ! $fashion
                || (FashionVocabulary::garmentFor($p->category, $p->name) !== null && ! FashionVocabulary::forMenOrKids($p->category, $p->name, $p->evidence['description'] ?? null)))
            ->map(fn (CompetitorProduct $p) => [
                'features' => CompetitorSignals::features($p),
                'recent' => in_array('new_arrival', $this->competitorSignals->signals($p, $firstSeen[$p->competitor_id] ?? null), true),
                'weight' => $p->is_best_seller ? 2.0 : 1.0,
                'platform' => $p->competitor?->platform,
                'product' => true,
            ]);

        $posts = SocialPost::query()->where('store_id', $store->id)
            ->whereIn('competitor_id', $active)
            ->when($live, fn ($q) => $q->whereNotNull('evidence'))
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '>=', now()->subDays(90)))
            ->get();
        $lifts = $this->competitorSignals->lifts($posts);

        $posts = $posts->map(fn (SocialPost $p) => [
            'features' => $p->features ?: $this->features->fromText((string) $p->caption),
            'recent' => (bool) ($p->posted_at ?? $p->created_at)?->greaterThan(now()->subDays(30)),
            'weight' => max(0.5, min(4.0, (float) ($lifts[$p->id]['lift'] ?? 1.0))),
            'platform' => $p->platform,
            'product' => false,
        ]);

        return $products->concat($posts)->values();
    }

    /**
     * Whether an earlier analysis already ran on real scans.
     */
    private function ranOnLiveData(Store $store): bool
    {
        return SyncRun::query()->where('store_id', $store->id)->where('type', 'trend_analysis')->where('status', 'completed')
            ->get(['stats'])->contains(fn (SyncRun $r) => ($r->stats['basis'] ?? null) === 'live');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $signals
     * @return Collection<string, array{recent: float, older: float, count: int, products: int, platforms: list<string>}>
     */
    private function aggregate(Collection $signals): Collection
    {
        $stats = [];

        foreach ($signals as $signal) {
            foreach (self::FIELDS as $field => [$type]) {
                $values = (array) ($signal['features'][$field] ?? []);

                foreach (array_filter($values) as $value) {
                    $key = "{$type}|{$value}";
                    $stats[$key] ??= ['recent' => 0.0, 'older' => 0.0, 'count' => 0, 'products' => 0, 'platforms' => []];
                    $stats[$key][$signal['recent'] ? 'recent' : 'older'] += $signal['weight'];
                    $stats[$key]['count']++;
                    $stats[$key]['products'] += (int) $signal['product'];

                    if ($signal['platform'] && ! in_array($signal['platform'], $stats[$key]['platforms'], true)) {
                        $stats[$key]['platforms'][] = $signal['platform'];
                    }
                }
            }
        }

        return collect($stats);
    }

    /**
     * @param  Collection<string, array{recent: float, older: float}>  $stats
     * @return array<string, array{recent: float, older: float}>
     */
    private function typeTotals(Collection $stats): array
    {
        $totals = [];

        foreach ($stats as $key => $s) {
            $type = strtok($key, '|');
            $totals[$type] ??= ['recent' => 0.0, 'older' => 0.0];
            $totals[$type]['recent'] += $s['recent'];
            $totals[$type]['older'] += $s['older'];
        }

        return $totals;
    }

    private function status(float $growth): string
    {
        return $growth >= 10 ? 'rising' : ($growth <= -10 ? 'declining' : 'stable');
    }

    private function productHas(Product $product, Trend $trend): bool
    {
        $f = $product->features ?? [];

        return match ($trend->type) {
            'color' => in_array($trend->value, $f['colors'] ?? [], true),
            'theme' => ($f['season'] ?? null) === $trend->value,
            default => ($f[$trend->type] ?? null) === $trend->value,
        };
    }

    private function notify(Store $store, Trend $trend): void
    {
        $gapAr = $trend->in_catalog ? 'وموجود في كتالوجك.' : 'وغير موجود في كتالوجك حاليًا — فرصة فجوة.';
        $gapEn = $trend->in_catalog ? 'and it is already in your catalog.' : 'and it is missing from your catalog — a gap opportunity.';

        foreach ($store->members as $member) {
            $member->notify(new PlatformNotification(
                kind: 'trend',
                titleAr: "توجه جديد صاعد: {$trend->name_ar}",
                titleEn: "New rising trend: {$trend->name_en}",
                bodyAr: "نمو {$trend->growth}% وزخم {$trend->momentum}/100 {$gapAr}",
                bodyEn: "Growth {$trend->growth}%, momentum {$trend->momentum}/100, {$gapEn}",
                url: '/store/market',
                storeId: $store->id,
                level: 'info',
                email: true,
            ));
        }
    }
}
