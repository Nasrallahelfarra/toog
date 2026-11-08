<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeReviews;
use App\Jobs\DetectTrends;
use App\Models\CompetitorProduct;
use App\Models\CustomerReview;
use App\Models\SocialPost;
use App\Models\Trend;
use App\Services\Market\SentimentAnalyzer;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "توجهات السوق" — trends, gaps, competitor activity, social content and
 * customer review insights (module 2).
 */
class MarketController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, SentimentAnalyzer $sentiment): Response
    {
        $store = $this->current->require();
        $filters = $request->only(['type', 'platform', 'status']);

        $trends = Trend::query()
            ->where('store_id', $store->id)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->get()
            ->when($filters['platform'] ?? null, fn ($c, $platform) => $c->filter(fn (Trend $t) => in_array($platform, $t->platforms ?? [], true)))
            ->sortBy([
                fn (Trend $a, Trend $b) => $this->statusRank($a->status) <=> $this->statusRank($b->status),
                fn (Trend $a, Trend $b) => $b->momentum <=> $a->momentum,
            ])
            ->values()
            ->map(fn (Trend $t) => $this->trend($t));

        $allTrends = Trend::query()->where('store_id', $store->id)->get();

        $gaps = $allTrends
            ->filter(fn (Trend $t) => $t->status === 'rising' && ! $t->in_catalog)
            ->sortByDesc(fn (Trend $t) => $t->momentum - $t->saturation / 3)
            ->take(6)
            ->values()
            ->map(fn (Trend $t) => $this->trend($t));

        $reviews = CustomerReview::query()->where('store_id', $store->id)->get();
        $analyzed = $reviews->whereNotNull('analyzed_at');

        return Inertia::render('store/market', [
            'filters' => $filters,
            'trends' => $trends,
            'gaps' => $gaps,
            'stats' => [
                'rising' => $allTrends->where('status', 'rising')->count(),
                'gaps' => $allTrends->where('status', 'rising')->where('in_catalog', false)->count(),
                'competitors' => $store->competitors()->where('is_active', true)->count(),
                'avg_engagement' => round((float) SocialPost::query()->where('store_id', $store->id)->measured()->avg('engagement_rate'), 2),
                'tracked_products' => CompetitorProduct::query()->where('store_id', $store->id)->count(),
            ],
            'activity' => $this->activityFeed($store->id),
            'posts' => SocialPost::query()
                ->with('competitor:id,name')
                ->where('store_id', $store->id)
                ->measured()
                ->orderByDesc('engagement_rate')
                ->limit(8)
                ->get()
                ->map(fn (SocialPost $p) => [
                    'id' => $p->id,
                    'platform' => $p->platform,
                    'competitor' => $p->competitor?->name,
                    'caption' => $p->caption,
                    'url' => $p->url,
                    'views' => $p->views,
                    'likes' => $p->likes,
                    'shares' => $p->shares,
                    'comments' => $p->comments,
                    'engagement_rate' => (float) $p->engagement_rate,
                    'colors' => $p->features['colors'] ?? [],
                    'posted_at' => $p->posted_at?->toIso8601String(),
                ]),
            'reviews' => [
                'total' => $reviews->count(),
                'pending' => $reviews->whereNull('analyzed_at')->count(),
                'sentiment' => [
                    'positive' => $analyzed->where('sentiment', 'positive')->count(),
                    'neutral' => $analyzed->where('sentiment', 'neutral')->count(),
                    'negative' => $analyzed->where('sentiment', 'negative')->count(),
                ],
                'topics' => $sentiment->topicSummary($analyzed),
                'recent' => $reviews->sortByDesc('created_at')->take(6)->values()->map(fn (CustomerReview $r) => [
                    'id' => $r->id,
                    'text' => $r->text,
                    'rating' => $r->rating,
                    'sentiment' => $r->sentiment,
                    'topics' => $r->topics ?? [],
                    'source' => $r->source,
                    'language' => $r->language,
                ]),
            ],
            'platforms' => $allTrends->pluck('platforms')->flatten()->filter()->unique()->values(),
            'lastAnalysis' => $store->syncRuns()->where('type', 'trend_analysis')->latest('finished_at')->value('finished_at'),
        ]);
    }

    public function refreshTrends(): RedirectResponse
    {
        $store = $this->current->require();

        DetectTrends::dispatch($store->id);
        Activity::log('market.trends_refreshed', $store);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('market.trends_refreshing')]);

        return back();
    }

    public function analyzeReviews(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        AnalyzeReviews::dispatch($store->id, $request->user()->id);
        Activity::log('market.reviews_analyzed', $store);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('market.reviews_analyzing')]);

        return back();
    }

    /**
     * New listings, price drops and best sellers from competitor stores.
     *
     * @return list<array<string, mixed>>
     */
    private function activityFeed(int $storeId): array
    {
        $base = fn () => CompetitorProduct::query()->with('competitor:id,name,platform')->where('store_id', $storeId);

        $row = fn (CompetitorProduct $p, string $kind, $date) => [
            'id' => "{$kind}-{$p->id}",
            'kind' => $kind,
            'name' => $p->name,
            'competitor' => $p->competitor?->name,
            'platform' => $p->competitor?->platform,
            'price' => $p->price !== null ? (float) $p->price : null,
            'previous_price' => $p->previous_price !== null ? (float) $p->previous_price : null,
            'url' => $p->url,
            'colors' => $p->features['colors'] ?? [],
            'date' => $date?->toIso8601String(),
        ];

        $new = $base()->where('first_seen_at', '>=', now()->subDays(30))->latest('first_seen_at')->limit(8)->get()
            ->map(fn ($p) => $row($p, 'new', $p->first_seen_at));
        $drops = $base()->whereNotNull('previous_price')->whereColumn('previous_price', '>', 'price')->latest('last_seen_at')->limit(6)->get()
            ->map(fn ($p) => $row($p, 'price_drop', $p->last_seen_at));
        $best = $base()->where('is_best_seller', true)->latest('last_seen_at')->limit(6)->get()
            ->map(fn ($p) => $row($p, 'best_seller', $p->last_seen_at));

        return $new->concat($drops)->concat($best)->sortByDesc('date')->take(14)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function trend(Trend $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'value' => $t->value,
            'name_ar' => $t->name_ar,
            'name_en' => $t->name_en,
            'momentum' => $t->momentum,
            'growth' => (float) $t->growth,
            'saturation' => $t->saturation,
            'status' => $t->status,
            'platforms' => $t->platforms ?? [],
            'in_catalog' => $t->in_catalog,
            'detected_at' => $t->detected_at?->toIso8601String(),
        ];
    }

    private function statusRank(string $status): int
    {
        return ['rising' => 0, 'stable' => 1, 'declining' => 2][$status] ?? 3;
    }
}
