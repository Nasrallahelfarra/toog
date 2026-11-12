<?php

namespace App\Services\Designer;

use App\Models\DesignerBriefing;
use App\Models\Store;
use App\Services\Ai\Ai;

/**
 * The designer's advice: up to five short, grounded recommendations drawn
 * from the store insights. The model only sees ids and numbers from the
 * facts; any item that cites an unknown id or a number not in the facts
 * is dropped, and templates take over when nothing survives.
 */
class DesignerAdvisor
{
    public function __construct(
        private readonly Ai $ai,
        private readonly DesignerInsights $insights,
    ) {}

    public function advise(Store $store, ?int $userId = null): DesignerBriefing
    {
        $ar = $this->insights->build($store, 'ar');
        $facts = $this->facts($ar);
        $prefills = $this->prefills($ar);

        $result = $this->ai->json(
            operation: 'designer_advice',
            store: $store,
            system: 'You are the head designer and merchandiser advising a Saudi modest-fashion online store. '
                .'Give at most five short, practical recommendations about what to design, extend, restock or avoid next, ranked by impact. '
                .'Base every item ONLY on the facts JSON: cite at least one id from it in refs, and use no number that is not in the facts. '
                .'Never mention revenue, profit or money earned. A competitor best-seller label is the merchant\'s own claim; homepage placement is not ad spend; engagement is not sales. '
                .'Phrase causes as hypotheses. title is at most 8 words; body at most 35 words. '
                .'action is one of design, restock, redesign, watch. Arabic in Modern Standard, Saudi-friendly; English plain.',
            prompt: json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            schema: ['type' => 'object', 'properties' => [
                'items' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => [
                    'title_ar' => ['type' => 'string'], 'body_ar' => ['type' => 'string'],
                    'title_en' => ['type' => 'string'], 'body_en' => ['type' => 'string'],
                    'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'action' => ['type' => 'string'],
                ], 'required' => ['title_ar', 'body_ar', 'title_en', 'body_en', 'refs', 'action']]],
            ], 'required' => ['items']],
            fallback: fn () => ['items' => []],
            userId: $userId,
            timeoutSeconds: 90,
        );

        $items = $this->grounded((array) ($result->data['items'] ?? []), $facts, $prefills);
        $fromModel = $result->fromModel && $items !== [];

        return DesignerBriefing::query()->create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'from_model' => $fromModel,
            'advice' => ['items' => $items !== [] ? $items : $this->templates($ar, $prefills)],
        ]);
    }

    /**
     * Compact facts with typed ids; no money.
     *
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    public function facts(array $i): array
    {
        return [
            'window' => $i['window'],
            'coverage' => $i['coverage'],
            'my_bestsellers' => array_map(fn ($p) => ['id' => "P:{$p['id']}", 'name' => $p['name'], 'category' => $p['category'], 'units_3m' => $p['units'], 'orders_3m' => $p['orders'],
                'return_pct' => $p['return_pct'], 'growth_pct' => $p['growth_pct'], 'stock' => $p['stock'], 'months_of_stock' => $p['months_of_stock'], 'colors' => $p['colors'], 'pattern' => $p['pattern']], $i['bestsellers']),
            'dna' => array_map(fn ($d) => ['id' => "D:{$d['attribute']}:{$d['value']}", 'attribute' => $d['attribute'], 'value' => $d['value'], 'sales_index' => $d['index'], 'products' => $d['products']], $i['dna']),
            'rising' => array_map(fn ($p) => ['id' => "R:{$p['id']}", 'name' => $p['name'], 'units_3m' => $p['units'], 'share_change_pct' => $p['change_pct'], 'stock' => $p['stock']], $i['rising']),
            'fading' => array_map(fn ($p) => ['id' => "F:{$p['id']}", 'name' => $p['name'], 'units_3m' => $p['units'], 'share_change_pct' => $p['change_pct'], 'stock' => $p['stock']], $i['fading']),
            'competitor_hits' => array_map(fn ($h) => ['id' => "C:{$h['id']}", 'competitor' => $h['competitor'], 'name' => $h['name'], 'signals' => $h['signals'],
                'price_drop_pct' => $h['drop_pct'], 'colors' => $h['colors'], 'pattern' => $h['pattern'], 'similar_to' => $h['similar_to'] ? "P:{$h['similar_to']['id']}" : null], $i['competitor_hits']),
            'social' => array_map(fn ($s) => ['id' => "S:{$s['id']}", 'competitor' => $s['competitor'], 'times_usual_engagement' => $s['times_median'], 'colors' => $s['colors']], $i['social']),
            'gaps' => array_map(fn ($g) => ['id' => "G:{$g['attribute']}:{$g['value']}", 'attribute' => $g['attribute'], 'value' => $g['value'], 'label' => $g['label'],
                'market_pct' => $g['market_pct'], 'mine_pct' => $g['mine_pct'], 'competitors' => $g['competitors']], $i['gaps']),
            'saturation' => $i['saturation'],
            'seasons' => array_map(fn ($e) => ['id' => "E:{$e['id']}", 'name' => $e['name'], 'days_until' => $e['days_until'], 'ready_products' => $e['ready_products'], 'status' => $e['status']], $i['seasons']),
        ];
    }

    /**
     * Keep items whose refs exist and whose numbers all appear in the facts.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $facts
     * @param  array<string, array<string, mixed>>  $prefills
     * @return list<array<string, mixed>>
     */
    private function grounded(array $items, array $facts, array $prefills): array
    {
        $json = json_encode($facts, JSON_UNESCAPED_UNICODE);
        preg_match_all('/"id":"([^"]+)"/', (string) $json, $m);
        $ids = array_flip($m[1]);
        // The windows themselves ("the last 3 months") are facts too.
        $known = [...$this->numbers((string) $json), 1, 2, 3, 6, 12, 30, 90];

        return collect($items)
            ->filter(fn ($item) => is_array($item) && is_string($item['title_ar'] ?? null) && is_string($item['body_ar'] ?? null))
            ->map(fn (array $item) => [...$item, 'refs' => array_values(array_filter((array) ($item['refs'] ?? []), fn ($r) => is_string($r) && isset($ids[$r])))])
            ->filter(fn (array $item) => $item['refs'] !== [])
            ->filter(function (array $item) use ($known) {
                foreach ($this->numbers($item['body_ar'].' '.($item['body_en'] ?? '').' '.$item['title_ar'].' '.($item['title_en'] ?? '')) as $n) {
                    if (! collect($known)->contains(fn ($k) => abs($k - $n) <= 1)) {
                        return false;
                    }
                }

                return true;
            })
            ->take(5)
            ->map(fn (array $item) => [
                'title_ar' => trim($item['title_ar']), 'body_ar' => trim($item['body_ar']),
                'title_en' => trim((string) ($item['title_en'] ?? '')), 'body_en' => trim((string) ($item['body_en'] ?? '')),
                'refs' => $item['refs'],
                'action' => in_array($item['action'] ?? null, ['design', 'restock', 'redesign', 'watch'], true) ? $item['action'] : 'watch',
                'prefill' => collect($item['refs'])->map(fn ($r) => $prefills[$r] ?? null)->filter()->first(),
                'href' => $this->href($item['refs'][0]),
            ])
            ->values()
            ->all();
    }

    /**
     * One plain recommendation per module, from its top row.
     *
     * @param  array<string, mixed>  $i
     * @param  array<string, array<string, mixed>>  $prefills
     * @return list<array<string, mixed>>
     */
    private function templates(array $i, array $prefills): array
    {
        $out = [];

        if ($p = $i['bestsellers'][0] ?? null) {
            $out[] = ['title_ar' => 'ابنِ على أفضل ما يبيع', 'body_ar' => "«{$p['name']}» باع {$p['units']} قطعة في آخر 3 أشهر. صمّم امتدادًا له بنفس البصمة مع تغيير واحد مدروس.",
                'title_en' => 'Build on your best seller', 'body_en' => "“{$p['name']}” sold {$p['units']} units in 3 months. Design an extension with the same DNA and one deliberate change.",
                'refs' => ["P:{$p['id']}"], 'action' => 'design', 'prefill' => $prefills["P:{$p['id']}"] ?? null, 'href' => "/store/products/{$p['id']}"];
        }
        if ($g = $i['gaps'][0] ?? null) {
            $out[] = ['title_ar' => "سدّ فجوة «{$g['label']}»",
                'body_ar' => $g['market_pct'] !== null ? "يظهر في {$g['market_pct']}% من المنتجات الرائجة لدى المنافسين مقابل {$g['mine_pct']}% في كتالوجك." : 'توجّه صاعد في السوق غير موجود في كتالوجك بعد.',
                'title_en' => "Fill the “{$g['label']}” gap",
                'body_en' => $g['market_pct'] !== null ? "It appears in {$g['market_pct']}% of competitor hits vs {$g['mine_pct']}% of your catalog." : 'A rising market trend you do not carry yet.',
                'refs' => ["G:{$g['attribute']}:{$g['value']}"], 'action' => 'design', 'prefill' => $g['prefill'], 'href' => null];
        }
        if ($e = collect($i['seasons'])->first(fn ($s) => $s['status'] !== 'ready')) {
            $out[] = ['title_ar' => "جهّز {$e['name']}", 'body_ar' => DesignerInsights::daysAr($e['days_until'])." وفي كتالوجك {$e['ready_products']} منتج جاهز له. ابدأ مجموعة الموسم الآن.",
                'title_en' => "Get ready for {$e['name']}", 'body_en' => "{$e['days_until']} days away with {$e['ready_products']} ready products. Start the season's collection now.",
                'refs' => ["E:{$e['id']}"], 'action' => 'design', 'prefill' => $e['prefill'], 'href' => null];
        }
        if ($f = $i['fading'][0] ?? null) {
            $out[] = ['title_ar' => "أعد التفكير في «{$f['name']}»", 'body_ar' => 'حصته من المبيعات تراجعت '.abs($f['change_pct'])."% والمخزون {$f['stock']} قطعة. افتح صفحة المنتج لاقتراح إعادة تصميم.",
                'title_en' => "Rethink “{$f['name']}”", 'body_en' => 'Its share of sales fell '.abs($f['change_pct'])."% with {$f['stock']} units in stock. Open the product for a redesign.",
                'refs' => ["F:{$f['id']}"], 'action' => 'redesign', 'prefill' => null, 'href' => "/store/products/{$f['id']}"];
        }
        if ($h = $i['competitor_hits'][0] ?? null) {
            $out[] = ['title_ar' => "راقب «{$h['name']}» لدى {$h['competitor']}", 'body_ar' => 'منتج يحمل إشارات بيع علنية لدى المنافس. صمّم ردًّا يغيّر عنصرين على الأقل بروح متجرك.',
                'title_en' => "Watch “{$h['name']}” at {$h['competitor']}", 'body_en' => 'A product with public selling signals. Design an answer that changes at least two elements, in your style.',
                'refs' => ["C:{$h['id']}"], 'action' => 'design', 'prefill' => $h['prefill'], 'href' => $h['url']];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, array<string, mixed>>
     */
    private function prefills(array $i): array
    {
        $out = [];
        foreach ($i['bestsellers'] as $p) {
            $out["P:{$p['id']}"] = $p['prefill'];
        }
        foreach ($i['rising'] as $p) {
            $out["R:{$p['id']}"] = $p['prefill'];
        }
        foreach ($i['competitor_hits'] as $h) {
            $out["C:{$h['id']}"] = $h['prefill'];
        }
        foreach ($i['social'] as $s) {
            $out["S:{$s['id']}"] = $s['prefill'];
        }
        foreach ($i['gaps'] as $g) {
            $out["G:{$g['attribute']}:{$g['value']}"] = $g['prefill'];
        }
        foreach ($i['seasons'] as $e) {
            $out["E:{$e['id']}"] = $e['prefill'];
        }

        return $out;
    }

    private function href(string $ref): ?string
    {
        return match (true) {
            (bool) preg_match('/^[PRF]:(\d+)$/', $ref, $m) => "/store/products/{$m[1]}",
            str_starts_with($ref, 'C:'), str_starts_with($ref, 'S:') => '/store/competitors',
            default => null,
        };
    }

    /**
     * Numbers in a text, Arabic-Indic digits included.
     *
     * @return list<float>
     */
    private function numbers(string $text): array
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        preg_match_all('/\d+(?:\.\d+)?/', $text, $m);

        return array_map('floatval', $m[0]);
    }
}
