<?php

namespace App\Services\Market;

use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\Trend;
use App\Notifications\PlatformNotification;
use App\Support\Vocabulary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recomputes the store's market trends (FR-2.5 – FR-2.7) from what
 * competitors list and post: how fast a colour / pattern / style / material
 * / season is growing (momentum), how widespread it already is
 * (saturation), and whether the store already carries it (gap).
 *
 * Existing trends are smoothed rather than replaced, so one noisy scan
 * cannot flip a trend; a new rising trend triggers a notification.
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

    public function __construct(private readonly FeatureExtractor $features) {}

    /**
     * @return array{updated: int, created: list<string>}
     */
    public function detect(Store $store): array
    {
        $run = SyncRun::query()->create(['store_id' => $store->id, 'type' => 'trend_analysis', 'status' => 'running', 'started_at' => now()]);

        $this->features->enrich($store->id);

        $signals = $this->signals($store);
        $stats = $this->aggregate($signals);
        $productTotal = max(1, CompetitorProduct::query()->where('store_id', $store->id)->count());
        $catalog = Product::query()->where('store_id', $store->id)->get();

        $existing = Trend::query()->where('store_id', $store->id)->get()->keyBy(fn (Trend $t) => "{$t->type}|{$t->value}");
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

        // Gap analysis (FR-2.6): does the store already carry each trend?
        foreach (Trend::query()->where('store_id', $store->id)->get() as $trend) {
            $trend->update(['in_catalog' => $catalog->contains(fn (Product $p) => $this->productHas($p, $trend))]);
        }

        foreach ($created as $trend) {
            $this->notify($store, $trend->fresh());
        }

        $run->update(['status' => 'completed', 'finished_at' => now(), 'stats' => ['updated' => $updated, 'created' => count($created), 'signals' => $signals->count()]]);

        return ['updated' => $updated, 'created' => array_map(fn (Trend $t) => $t->name_en, $created)];
    }

    /**
     * Weighted observations: competitor listings (best sellers count double)
     * and social posts (weighted by reach and engagement).
     *
     * @return Collection<int, array{features: array<string, mixed>, date: Carbon, weight: float, platform: string|null, product: bool}>
     */
    private function signals(Store $store): Collection
    {
        $products = CompetitorProduct::query()->with('competitor:id,platform')->where('store_id', $store->id)->get()
            ->map(fn (CompetitorProduct $p) => [
                'features' => $p->features ?? [],
                'date' => $p->first_seen_at ?? $p->created_at,
                'weight' => $p->is_best_seller ? 2.0 : 1.0,
                'platform' => $p->competitor?->platform,
                'product' => true,
            ]);

        $posts = SocialPost::query()->where('store_id', $store->id)->get()
            ->map(fn (SocialPost $p) => [
                'features' => $p->features ?? [],
                'date' => $p->posted_at ?? $p->created_at,
                'weight' => 1 + min(3, $p->views / 250_000) + (float) $p->engagement_rate / 5,
                'platform' => $p->platform,
                'product' => false,
            ]);

        return $products->concat($posts)->filter(fn ($s) => $s['date'] && $s['date']->greaterThan(now()->subDays(90)))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $signals
     * @return Collection<string, array{recent: float, older: float, count: int, products: int, platforms: list<string>}>
     */
    private function aggregate(Collection $signals): Collection
    {
        $stats = [];
        $cutoff = now()->subDays(30);

        foreach ($signals as $signal) {
            foreach (self::FIELDS as $field => [$type]) {
                $values = (array) ($signal['features'][$field] ?? []);

                foreach (array_filter($values) as $value) {
                    $key = "{$type}|{$value}";
                    $stats[$key] ??= ['recent' => 0.0, 'older' => 0.0, 'count' => 0, 'products' => 0, 'platforms' => []];
                    $stats[$key][$signal['date']->greaterThan($cutoff) ? 'recent' : 'older'] += $signal['weight'];
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
