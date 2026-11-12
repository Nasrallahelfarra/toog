<?php

namespace App\Services\Reports;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\CustomerReview;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\Trend;
use App\Services\Catalog\BalanceService;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the four ready-made reports (FR-6.2) as plain data — summary
 * figures plus tables — so the same content feeds the Excel export and
 * the printable PDF page. Labels come in Arabic and English.
 *
 * Report shape:
 *  [type, title_ar, title_en, summary: [{label_ar, label_en, value, format}],
 *   sections: [{key, title_ar, title_en, columns: [{key, ar, en, format}], rows: [...]}]]
 */
class ReportBuilder
{
    public const TYPES = ['performance', 'market', 'evaluations', 'balance'];

    private const TITLES = [
        'performance' => ['تقرير أداء المتجر', 'Store performance report'],
        'market' => ['تقرير المنافسين والتوجهات', 'Competitors & trends report'],
        'evaluations' => ['تقرير التقييمات والقرارات', 'Evaluations & decisions report'],
        'balance' => ['تقرير توازن الكتالوج', 'Catalog balance report'],
    ];

    private const PLATFORMS = [
        'salla' => ['سلة', 'Salla'], 'zid' => ['زد', 'Zid'], 'shopify' => ['Shopify', 'Shopify'],
        'instagram' => ['إنستغرام', 'Instagram'], 'tiktok' => ['تيك توك', 'TikTok'], 'facebook' => ['فيسبوك', 'Facebook'],
        'snapchat' => ['سناب شات', 'Snapchat'],
    ];

    private const TOPICS = [
        'fabric' => ['الخامة', 'Fabric'], 'fit' => ['القصّة', 'Fit'], 'sizing' => ['المقاسات', 'Sizing'],
        'color_accuracy' => ['دقة اللون', 'Colour accuracy'], 'quality' => ['الجودة', 'Quality'], 'price' => ['السعر', 'Price'],
        'delivery' => ['التوصيل', 'Delivery'], 'packaging' => ['التغليف', 'Packaging'], 'design' => ['التصميم', 'Design'],
        'service' => ['خدمة العملاء', 'Customer service'],
    ];

    private string $locale = 'ar';

    public function __construct(private readonly BalanceService $balance) {}

    /**
     * @param  array<string, mixed>  $filters  evaluations: recommendation, final_decision, category, q
     * @return array<string, mixed>
     */
    public function build(string $type, Store $store, CarbonImmutable $from, CarbonImmutable $to, array $filters = [], bool $summaryOnly = false, string $locale = 'ar'): array
    {
        $this->locale = $locale;

        [$summary, $sections] = match ($type) {
            'performance' => $this->withoutRevenue($store, $this->performance($store, $from, $to, $summaryOnly)),
            'market' => $this->market($store, $from, $to, $summaryOnly),
            'evaluations' => $this->evaluations($store, $from, $to, $filters, $summaryOnly),
            'balance' => $this->balanceReport($store, $summaryOnly),
        };

        return [
            'type' => $type,
            'title_ar' => self::TITLES[$type][0],
            'title_en' => self::TITLES[$type][1],
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function performance(Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $summaryOnly): array
    {
        $sales = ProductSale::query()
            ->where('store_id', $store->id)
            ->whereBetween('period', [$from->startOfMonth()->toDateString(), $to->toDateString()])
            ->get();

        $byProduct = $sales->groupBy('product_id');
        $products = Product::query()->where('store_id', $store->id)->get();
        $revenue = (float) $sales->sum('revenue');
        $units = (int) $sales->sum('units');

        $summary = [
            $this->metric('عدد المنتجات', 'Products', $products->count()),
            $this->metric('المنتجات الرابحة', 'Best sellers', $products->where('performance', Product::WINNER)->count()),
            $this->metric('متوسطة الأداء', 'Average', $products->where('performance', Product::AVERAGE)->count()),
            $this->metric('معرّضة للركود', 'At risk of stagnation', $products->where('performance', Product::AT_RISK)->count()),
            $this->metric('الراكدة', 'Stagnant', $products->where('performance', Product::STAGNANT)->count()),
            $this->metric('الإيرادات', 'Revenue', $revenue, 'money'),
            $this->metric('القطع المباعة', 'Units sold', $units),
        ];

        if ($summaryOnly) {
            return [$summary, []];
        }

        $productRows = $products->sortByDesc('performance_score')->values()->map(function (Product $p) use ($byProduct) {
            $rows = $byProduct->get($p->id, collect());

            return [
                'name' => $p->name,
                'category' => $p->category ?? '—',
                'price' => $p->price !== null ? (float) $p->price : null,
                'performance' => $this->performanceLabel($p->performance),
                'score' => $p->performance_score,
                'units' => (int) $rows->sum('units'),
                'revenue' => (float) $rows->sum('revenue'),
                'add_to_cart_rate' => (float) $p->add_to_cart_rate,
                'return_rate' => (float) $p->return_rate,
                'turnover_days' => $p->turnover_days,
                'stock' => $p->stock,
            ];
        });

        $categories = $products->groupBy(fn (Product $p) => $p->category ?? '—')->map(fn (Collection $items, $category) => [
            'category' => $category,
            'products' => $items->count(),
            'revenue' => (float) $items->sum(fn (Product $p) => $byProduct->get($p->id, collect())->sum('revenue')),
            'winners' => $items->where('performance', Product::WINNER)->count(),
            'at_risk' => $items->where('performance', Product::AT_RISK)->count(),
            'stagnant' => $items->where('performance', Product::STAGNANT)->count(),
        ])->sortByDesc('revenue')->values();

        $months = $sales->groupBy(fn ($s) => $s->period->format('Y-m'))->sortKeys()->map(fn (Collection $rows, $month) => [
            'month' => $month,
            'orders' => (int) $rows->sum('orders'),
            'units' => (int) $rows->sum('units'),
            'revenue' => (float) $rows->sum('revenue'),
        ])->values();

        return [$summary, [
            $this->section('products', 'المنتجات وتصنيف الأداء', 'Products and performance class', [
                ['name', 'المنتج', 'Product'], ['category', 'الفئة', 'Category'], ['price', 'السعر', 'Price', 'money'],
                ['performance', 'التصنيف', 'Class'], ['score', 'درجة الأداء', 'Performance score', 'number'],
                ['units', 'القطع المباعة', 'Units', 'number'], ['revenue', 'الإيرادات', 'Revenue', 'money'],
                ['add_to_cart_rate', 'نسبة الإضافة للسلة', 'Add-to-cart rate', 'percent'],
                ['return_rate', 'نسبة المرتجعات', 'Return rate', 'percent'],
                ['turnover_days', 'أيام دوران المخزون', 'Turnover days', 'number'], ['stock', 'المخزون', 'Stock', 'number'],
            ], $productRows),
            $this->section('categories', 'الأداء حسب الفئة', 'Performance by category', [
                ['category', 'الفئة', 'Category'], ['products', 'المنتجات', 'Products', 'number'],
                ['revenue', 'الإيرادات', 'Revenue', 'money'], ['winners', 'الرابحة', 'Best sellers', 'number'],
                ['at_risk', 'معرّضة للركود', 'At risk of stagnation', 'number'],
                ['stagnant', 'الراكدة', 'Stagnant', 'number'],
            ], $categories),
            $this->section('monthly', 'المبيعات الشهرية', 'Monthly sales', [
                ['month', 'الشهر', 'Month'], ['orders', 'الطلبات', 'Orders', 'number'],
                ['units', 'القطع', 'Units', 'number'], ['revenue', 'الإيرادات', 'Revenue', 'money'],
            ], $months),
        ]];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function market(Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $summaryOnly): array
    {
        $trends = Trend::query()->where('store_id', $store->id)->orderByDesc('momentum')->get();
        $competitors = Competitor::query()->where('store_id', $store->id)->withCount(['products', 'posts'])->get();
        $newProducts = CompetitorProduct::query()->with('competitor:id,name')
            ->where('store_id', $store->id)->whereBetween('first_seen_at', [$from, $to->endOfDay()])->latest('first_seen_at')->get();
        $reviews = CustomerReview::query()->where('store_id', $store->id)->whereNotNull('analyzed_at')
            ->whereBetween('created_at', [$from, $to->endOfDay()])->get();
        $positive = $reviews->count() ? round($reviews->where('sentiment', 'positive')->count() / $reviews->count() * 100) : null;

        $summary = [
            $this->metric('المنافسون المتابَعون', 'Competitors tracked', $competitors->where('is_active', true)->count()),
            $this->metric('منتجات جديدة لدى المنافسين', 'New competitor products', $newProducts->count()),
            $this->metric('توجهات صاعدة', 'Rising trends', $trends->where('status', 'rising')->count()),
            $this->metric('فرص فجوات', 'Gap opportunities', $trends->where('status', 'rising')->where('in_catalog', false)->count()),
            $this->metric('آراء إيجابية', 'Positive reviews', $positive, 'percent'),
        ];

        if ($summaryOnly) {
            return [$summary, []];
        }

        $statusLabel = fn (string $s) => $this->ar()
            ? ['rising' => 'صاعد', 'stable' => 'مستقر', 'declining' => 'متراجع'][$s] ?? $s
            : ucfirst($s);
        $typeLabel = fn (string $t) => $this->ar()
            ? ['color' => 'لون', 'pattern' => 'نقشة', 'style' => 'أسلوب', 'material' => 'خامة', 'shape' => 'شكل', 'theme' => 'موسم'][$t] ?? $t
            : ucfirst($t);

        $sentimentRows = collect(['positive' => ['إيجابي', 'Positive'], 'neutral' => ['محايد', 'Neutral'], 'negative' => ['سلبي', 'Negative']])
            ->map(fn ($label, $key) => [
                'sentiment' => $this->ar() ? $label[0] : $label[1],
                'count' => $reviews->where('sentiment', $key)->count(),
                'topics' => $reviews->where('sentiment', $key)->pluck('topics')->flatten()->filter()->countBy()->sortDesc()->take(4)->keys()
                    ->map(fn ($topic) => $this->label(self::TOPICS, $topic))->implode($this->ar() ? '، ' : ', '),
            ])->values();

        return [$summary, [
            $this->section('trends', 'التوجهات', 'Trends', [
                ['name', 'التوجه', 'Trend'], ['type', 'النوع', 'Type'], ['status', 'الحالة', 'Status'],
                ['momentum', 'الزخم', 'Momentum', 'number'], ['growth', 'النمو', 'Growth', 'percent'],
                ['saturation', 'التشبّع', 'Saturation', 'number'], ['in_catalog', 'في الكتالوج', 'In catalog'],
                ['platforms', 'المنصات', 'Platforms'],
            ], $trends->map(fn (Trend $t) => [
                'name' => $this->ar() ? $t->name_ar : $t->name_en,
                'type' => $typeLabel($t->type),
                'status' => $statusLabel($t->status),
                'momentum' => $t->momentum,
                'growth' => (float) $t->growth,
                'saturation' => $t->saturation,
                'in_catalog' => $this->yesNo($t->in_catalog),
                'platforms' => implode(', ', $t->platforms ?? []),
            ])),
            $this->section('competitors', 'المنافسون', 'Competitors', [
                ['name', 'المنافس', 'Competitor'], ['platform', 'المنصة', 'Platform'], ['products', 'المنتجات المرصودة', 'Products tracked', 'number'],
                ['posts', 'المنشورات', 'Posts', 'number'], ['last_checked', 'آخر فحص', 'Last checked', 'date'], ['active', 'نشط', 'Active'],
            ], $competitors->map(fn (Competitor $c) => [
                'name' => $c->name,
                'platform' => $this->label(self::PLATFORMS, $c->platform),
                'products' => $c->products_count,
                'posts' => $c->posts_count,
                'last_checked' => $c->last_checked_at?->toDateString(),
                'active' => $this->yesNo($c->is_active),
            ])),
            $this->section('competitor_products', 'منتجات المنافسين الجديدة', 'New competitor products', [
                ['competitor', 'المنافس', 'Competitor'], ['name', 'المنتج', 'Product'], ['price', 'السعر', 'Price', 'money'],
                ['previous_price', 'السعر السابق', 'Previous price', 'money'], ['best_seller', 'الأكثر مبيعًا', 'Best seller'],
                ['first_seen', 'تاريخ الظهور', 'First seen', 'date'],
            ], $newProducts->map(fn (CompetitorProduct $p) => [
                'competitor' => $p->competitor?->name,
                'name' => $p->name,
                'price' => $p->price !== null ? (float) $p->price : null,
                'previous_price' => $p->previous_price !== null ? (float) $p->previous_price : null,
                'best_seller' => $this->yesNo($p->is_best_seller),
                'first_seen' => $p->first_seen_at?->toDateString(),
            ])),
            $this->section('reviews', 'آراء العملاء', 'Customer sentiment', [
                ['sentiment', 'الانطباع', 'Sentiment'], ['count', 'العدد', 'Reviews', 'number'], ['topics', 'أبرز المواضيع', 'Top topics'],
            ], $sentimentRows),
        ]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function evaluations(Store $store, CarbonImmutable $from, CarbonImmutable $to, array $filters, bool $summaryOnly): array
    {
        $evaluations = Evaluation::query()
            ->with('decider:id,name')
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to->endOfDay()])
            ->when($filters['recommendation'] ?? null, fn ($q, $v) => $q->where('recommendation', $v))
            ->when($filters['final_decision'] ?? null, fn ($q, $v) => $v === 'pending' ? $q->whereNull('final_decision') : $q->where('final_decision', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->latest()
            ->get();

        $decided = $evaluations->whereNotNull('final_decision');

        $summary = [
            $this->metric('المنتجات المقيّمة', 'Products evaluated', $evaluations->count()),
            $this->metric('متوسط الدرجة', 'Average score', $evaluations->count() ? round($evaluations->avg('score')) : null, 'number'),
            $this->metric('توصيات القبول', 'Accept recommendations', $evaluations->where('recommendation', 'accept')->count()),
            $this->metric('توصيات التعديل', 'Modify recommendations', $evaluations->where('recommendation', 'modify')->count()),
            $this->metric('توصيات الرفض', 'Reject recommendations', $evaluations->where('recommendation', 'reject')->count()),
            $this->metric('تجاوزات التوصية', 'Overrides', $decided->whereNotNull('override_reason')->count()),
        ];

        if ($summaryOnly) {
            return [$summary, []];
        }

        return [$summary, [
            $this->section('evaluations', 'سجل التقييمات', 'Evaluation log', [
                ['date', 'التاريخ', 'Date', 'date'], ['name', 'المنتج', 'Product'], ['category', 'الفئة', 'Category'],
                ['price', 'السعر', 'Price', 'money'], ['version', 'النسخة', 'Version', 'number'], ['score', 'الدرجة', 'Score', 'number'],
                ['recommendation', 'التوصية', 'Recommendation'], ['final_decision', 'القرار النهائي', 'Final decision'],
                ['override_reason', 'سبب التجاوز', 'Override reason'], ['decided_by', 'متخذ القرار', 'Decided by'],
                ['suggested_launch', 'موعد الطرح المقترح', 'Suggested launch', 'date'],
            ], $evaluations->map(fn (Evaluation $e) => [
                'date' => $e->created_at?->toDateString(),
                'name' => $e->name,
                'category' => $e->category ?? '—',
                'price' => $e->price !== null ? (float) $e->price : null,
                'version' => $e->version,
                'score' => $e->score,
                'recommendation' => $this->decisionLabel($e->recommendation),
                'final_decision' => $this->decisionLabel($e->final_decision ?? 'pending'),
                'override_reason' => $e->override_reason,
                'decided_by' => $e->decider?->name,
                'suggested_launch' => $e->suggested_launch,
            ])),
        ]];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function balanceReport(Store $store, bool $summaryOnly): array
    {
        $analysis = $this->balance->analyze($store);
        $o = $analysis['overall'];

        $summary = [
            $this->metric('مؤشر التنوع', 'Diversity index', $o['diversity'], 'number'),
            $this->metric('نسبة المبتكر', 'Innovative share', $o['novel_ratio'], 'percent'),
            $this->metric('المستهدف للمبتكر', 'Innovative target', $o['target_novel'], 'percent'),
            $this->metric('طروحات آخر 6 أشهر', 'Launches (6 months)', $o['recent_launches']),
            $this->metric('التنبيهات النشطة', 'Active alerts', count($analysis['alerts'])),
        ];

        if ($summaryOnly) {
            return [$summary, []];
        }

        $status = fn (string $s) => $this->ar()
            ? ['healthy' => 'متوازن', 'repetitive' => 'متكرر', 'stale' => 'راكد الطرح', 'overloaded' => 'مثقل بالراكد'][$s] ?? $s
            : ['healthy' => 'Healthy', 'repetitive' => 'Repetitive', 'stale' => 'No recent launches', 'overloaded' => 'Too many slow movers'][$s] ?? $s;

        return [$summary, [
            $this->section('categories', 'التوازن حسب الفئة', 'Balance by category', [
                ['category', 'الفئة', 'Category'], ['products', 'المنتجات', 'Products', 'number'],
                ['diversity', 'مؤشر التنوع', 'Diversity', 'number'], ['top_look', 'أكثر شكل يتكرر', 'Most repeated look'],
                ['novel_ratio', 'نسبة المبتكر', 'Innovative share', 'percent'], ['winners', 'الرابحة', 'Best sellers', 'number'],
                ['stagnant', 'الراكدة', 'Stagnant', 'number'], ['recent_launches', 'طروحات حديثة', 'Recent launches', 'number'],
                ['recommended', 'الطرح المقترح شهريًا', 'Suggested per month', 'number'], ['status', 'الحالة', 'Status'],
            ], collect($analysis['categories'])->map(fn ($c) => [
                'category' => $c['category'],
                'products' => $c['products'],
                'diversity' => $c['diversity'],
                'top_look' => $c['top_signature'] ? $this->look($c['top_signature']) : '—',
                'novel_ratio' => $c['novel_ratio'],
                'winners' => $c['winners'],
                'stagnant' => $c['stagnant'],
                'recent_launches' => $c['recent_launches'],
                'recommended' => $c['recommended_per_month'],
                'status' => $status($c['status']),
            ])),
        ]];
    }

    /**
     * @param  array{colors: list<string>, pattern: string|null, style: string|null, share: int}  $signature
     */
    private function look(array $signature): string
    {
        $parts = array_filter([
            Vocabulary::label('colors', $signature['colors'][0] ?? null, $this->locale),
            Vocabulary::label('patterns', $signature['pattern'], $this->locale),
            Vocabulary::label('styles', $signature['style'], $this->locale),
        ]);

        return implode(' · ', $parts)." ({$signature['share']}%)";
    }

    /**
     * Drop every revenue figure from a report while the platform admin has
     * revenue switched off for the store: the summary tile and the columns.
     *
     * @param  array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}  $report
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function withoutRevenue(Store $store, array $report): array
    {
        if ($store->showsRevenue()) {
            return $report;
        }

        [$summary, $sections] = $report;

        return [
            array_values(array_filter($summary, fn (array $m) => ($m['format'] ?? null) !== 'money' || $m['label_en'] !== 'Revenue')),
            array_map(fn (array $section) => [
                ...$section,
                'columns' => array_values(array_filter($section['columns'], fn (array $c) => $c['key'] !== 'revenue')),
                'rows' => array_map(fn (array $row) => array_diff_key($row, ['revenue' => true]), $section['rows']),
            ], $sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metric(string $ar, string $en, int|float|null $value, string $format = 'number'): array
    {
        return ['label_ar' => $ar, 'label_en' => $en, 'value' => $value, 'format' => $format];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function section(string $key, string $ar, string $en, array $columns, Collection $rows): array
    {
        return [
            'key' => $key,
            'title_ar' => $ar,
            'title_en' => $en,
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'ar' => $c[1], 'en' => $c[2], 'format' => $c[3] ?? 'text'], $columns),
            'rows' => $rows->values()->all(),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $labels
     */
    private function label(array $labels, string $key): string
    {
        return $labels[$key][$this->ar() ? 0 : 1] ?? $key;
    }

    private function ar(): bool
    {
        return $this->locale === 'ar';
    }

    private function yesNo(bool $value): string
    {
        return $this->ar() ? ($value ? 'نعم' : 'لا') : ($value ? 'Yes' : 'No');
    }

    private function performanceLabel(?string $value): string
    {
        return match ($value) {
            Product::WINNER => $this->ar() ? 'رابح' : 'Best seller',
            Product::AVERAGE => $this->ar() ? 'متوسط' : 'Average',
            Product::AT_RISK => $this->ar() ? 'معرّض للركود' : 'At risk of stagnation',
            Product::STAGNANT => $this->ar() ? 'راكد' : 'Stagnant',
            default => '—',
        };
    }

    private function decisionLabel(?string $value): string
    {
        $labels = [
            'accept' => ['قبول', 'Accept'], 'reject' => ['رفض', 'Reject'], 'modify' => ['تعديل ومواءمة', 'Modify'],
            'approved' => ['معتمد', 'Approved'], 'rejected' => ['مرفوض', 'Rejected'], 'needs_changes' => ['يحتاج تعديل', 'Needs changes'],
            'pending' => ['بانتظار القرار', 'Awaiting decision'],
        ];

        return $value ? ($labels[$value][$this->ar() ? 0 : 1] ?? $value) : '—';
    }
}
