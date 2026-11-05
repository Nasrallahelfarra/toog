<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Services\Evaluation\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * The marketing half of a slow-mover recommendation: market facts about
 * the product for the expert, and the ready-to-run plan it answers with
 * (selling angle, Arabic ad copy, offer, channels, timing, success check).
 */
class RecoveryMarketing
{
    public const OFFERS = ['discount', 'bundle', 'free_shipping', 'gift', 'installments', 'price_reset', 'none'];

    public const CHANNELS = ['snapchat', 'tiktok', 'instagram', 'whatsapp', 'x', 'influencer', 'store_banner', 'email'];

    /** Days ahead in which an occasion is worth planning for. */
    private const HORIZON = 150;

    /**
     * Facts only, never instructions: price position in the category, what
     * the category's best sellers look like, stock pressure and occasions.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function context(Store $store, array $snapshot, StoreContext $ctx): array
    {
        $category = $snapshot['category'] ?? null;
        $peers = Product::query()->where('store_id', $store->id)
            ->when($category, fn (Builder $q) => $q->where('category', $category), fn (Builder $q) => $q->whereNull('category'))
            ->get(['price', 'performance', 'features']);
        $prices = $peers->pluck('price')->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p)->sort()->values();
        $price = isset($snapshot['price']) ? (float) $snapshot['price'] : null;
        $winners = $peers->where('performance', Product::WINNER);

        return [
            'market' => 'Saudi Arabia, online store, prices in SAR',
            'pricing' => [
                'price' => $price,
                'category_median' => $prices->isEmpty() ? null : round((float) $prices->median(), 2),
                'category_min' => $prices->first(),
                'category_max' => $prices->last(),
                'cheaper_peers_share' => $price !== null && $prices->isNotEmpty()
                    ? (int) round($prices->filter(fn ($p) => $p < $price)->count() / $prices->count() * 100)
                    : null,
                'best_sellers_median' => ($w = $winners->pluck('price')->filter()->map(fn ($p) => (float) $p))->isEmpty() ? null : round((float) $w->median(), 2),
            ],
            'category_best_sellers' => [
                'count' => $winners->count(),
                'colors' => $this->top($winners->flatMap(fn (Product $p) => (array) ($p->features['colors'] ?? []))),
                'patterns' => $this->top($winners->map(fn (Product $p) => $p->features['pattern'] ?? null)),
                'styles' => $this->top($winners->map(fn (Product $p) => $p->features['style'] ?? null)),
            ],
            'inventory' => [
                'stock' => $snapshot['stock'] ?? null,
                'days_to_sell_out' => $snapshot['turnover_days'] ?? null,
                'stock_value' => $price !== null ? round($price * (int) ($snapshot['stock'] ?? 0), 2) : null,
                'units_last_12_months' => $snapshot['units_sold'] ?? null,
            ],
            'occasions' => $ctx->upcomingEvents
                ->filter(fn ($e) => $e->starts_on->lte(now()->addDays(self::HORIZON)))
                ->take(5)
                ->map(fn ($e) => [
                    'name_ar' => $e->name_ar, 'name_en' => $e->name_en,
                    'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on->toDateString(),
                    'days_until' => max(0, (int) now()->startOfDay()->diffInDays($e->starts_on, false)),
                    'prep_weeks' => $e->prep_weeks, 'categories' => $e->categories,
                ])->values()->all(),
        ];
    }

    public function instructions(): string
    {
        return <<<'TXT'

        Also act as a senior e-commerce marketer for the Saudi market and return ONE
        marketing_plan that could move this product, grounded in market_context:
        - angle: the selling angle for this product (occasion, gifting, quality, value,
          elegance, practicality) chosen from the evidence; Saudi shoppers are diverse,
          so never rely on stereotypes.
        - copy: ready-to-publish ad copy. Arabic in natural, clear Saudi dialect suitable
          for social ads; English as a faithful translation. headline <= 60 characters,
          body <= 220 characters, cta <= 30 characters. No exaggeration, fake scarcity,
          invented features or any discount other than the offer below.
        - hashtags: 3-6 Arabic hashtags shoppers in Saudi Arabia actually search
          (product type, occasion), each starting with # and without spaces.
        - offer: one type from the allowed list with a concrete value in SAR or percent
          and a duration of 3-30 days. Weigh stock value, days to sell out and the price
          against category_median; margins are unknown, so prefer bundles, gifts or free
          shipping before deep discounts on premium items, and do not go below
          category_min unless days_to_sell_out justifies clearance. installments means
          payment messaging (for example Tabby or Tamara) for higher prices.
        - channels: up to 3 from the allowed list, each with one concrete execution
          idea for that channel (short video hook, carousel, WhatsApp broadcast to past
          buyers, store banner).
        - timing: the nearest occasion in market_context.occasions that fits this
          product, with when to start given prep_weeks; otherwise start now and say why.
        - kpi: one measurable check and when to review it, using data the store has
          (units or revenue; add-to-cart only when views exist).
        Keep copy and imagery respectful of Saudi culture. Write every narrative field
        in Arabic and English.
        TXT;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public function schema(callable $object): array
    {
        $text = ['type' => 'string'];
        $pair = $object(['ar' => $text, 'en' => $text]);

        return $object([
            'angle' => $pair,
            'copy' => $object(['headline' => $pair, 'body' => $pair, 'cta' => $pair]),
            'hashtags' => ['type' => 'array', 'maxItems' => 6, 'items' => $text],
            'offer' => $object(['type' => ['type' => 'string', 'enum' => self::OFFERS], 'value' => $pair, 'duration_days' => ['type' => 'integer'], 'rationale' => $pair]),
            'channels' => ['type' => 'array', 'maxItems' => 3, 'items' => $object(['channel' => ['type' => 'string', 'enum' => self::CHANNELS], 'idea' => $pair])],
            'timing' => $object(['occasion' => $pair, 'when' => $pair]),
            'kpi' => $pair,
        ]);
    }

    /**
     * The validated plan, or null when the model's answer does not hold up.
     *
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $plan): ?array
    {
        if (! is_array($plan)) {
            return null;
        }

        $rules = [
            'offer.type' => 'required|in:'.implode(',', self::OFFERS),
            'offer.duration_days' => 'required|integer',
            'hashtags' => 'present|array|max:6',
            'hashtags.*' => 'string|max:60',
            'channels' => 'present|array|max:3',
            'channels.*.channel' => 'required|in:'.implode(',', self::CHANNELS),
        ];
        foreach (['ar', 'en'] as $lang) {
            $rules += [
                "angle.$lang" => 'required|string|max:600',
                "copy.headline.$lang" => 'required|string|max:160',
                "copy.body.$lang" => 'required|string|max:600',
                "copy.cta.$lang" => 'required|string|max:80',
                "offer.value.$lang" => 'required|string|max:160',
                "offer.rationale.$lang" => 'required|string|max:800',
                "channels.*.idea.$lang" => 'required|string|max:400',
                "timing.occasion.$lang" => 'required|string|max:200',
                "timing.when.$lang" => 'required|string|max:400',
                "kpi.$lang" => 'required|string|max:500',
            ];
        }

        $validator = Validator::make($plan, $rules);
        if ($validator->fails()) {
            return null;
        }

        $clean = $validator->validated();
        $clean['offer']['duration_days'] = max(1, min(60, (int) $clean['offer']['duration_days']));
        $clean['hashtags'] = collect(array_filter(Arr::wrap($clean['hashtags']), 'is_string'))
            ->map(fn (string $tag) => '#'.ltrim(preg_replace('/\s+/u', '_', trim($tag)), '#'))
            ->filter(fn (string $tag) => mb_strlen($tag) > 1)
            ->unique()->values()->all();

        return $clean;
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return array<string, int>
     */
    private function top(Collection $values): array
    {
        return $values->filter()->countBy()->sortDesc()->take(3)->all();
    }
}
