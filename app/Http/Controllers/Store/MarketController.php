<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeReviews;
use App\Jobs\DetectTrends;
use App\Jobs\ImportZidReviews;
use App\Models\CustomerReview;
use App\Models\Store;
use App\Services\Market\MarketTrends;
use App\Services\Market\SentimentAnalyzer;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "توجهات السوق": the market (what active competitors carry and push,
 * clothing only) set against the store's own Zid sales, sorted into four
 * answers: opportunities you do not carry, what to focus on, what is
 * rising in your sales and what is cooling; plus the store's own customer
 * reviews, read from Zid.
 * Competitor posts and products in detail live on the competitor radar.
 */
class MarketController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, SentimentAnalyzer $sentiment, MarketTrends $trends): Response
    {
        $store = $this->current->require();

        return Inertia::render('store/market', [
            'board' => $trends->build($store),
            'reviews' => $this->reviews($store, $sentiment, $request->user()?->id),
            'lastAnalysis' => $store->syncRuns()->where('type', 'trend_analysis')->latest('finished_at')->value('finished_at'),
        ]);
    }

    /**
     * The store's customer reviews: read from Zid once the store is
     * connected (seeded samples are never shown there), with when they
     * were last read and why a read failed.
     *
     * @return array<string, mixed>
     */
    private function reviews(Store $store, SentimentAnalyzer $sentiment, ?int $userId): array
    {
        $zid = CustomerReview::fromZid($store);
        $run = $zid ? $store->syncRuns()->where('type', 'zid_reviews')->latest('id')->first() : null;
        // The first visit after connecting reads the reviews once; later
        // reads follow each Zid sync or the merchant's own request.
        if ($zid && ! $run) {
            ImportZidReviews::dispatch($store->id, $userId);
        }

        $reviews = CustomerReview::query()->shownFor($store)->get();
        $analyzed = $reviews->whereNotNull('analyzed_at');
        $rated = $reviews->whereNotNull('rating');

        return [
            'source' => $zid ? 'zid' : ($reviews->isEmpty() ? 'none' : 'sample'),
            'total' => $reviews->count(),
            'pending' => $reviews->whereNull('analyzed_at')->count(),
            'average_rating' => $rated->isNotEmpty() ? round((float) $rated->avg('rating'), 1) : null,
            'sentiment' => [
                'positive' => $analyzed->where('sentiment', 'positive')->count(),
                'neutral' => $analyzed->where('sentiment', 'neutral')->count(),
                'negative' => $analyzed->where('sentiment', 'negative')->count(),
            ],
            'topics' => $sentiment->topicSummary($analyzed),
            'recent' => $reviews->sortByDesc(fn (CustomerReview $r) => ($r->reviewed_at ?? $r->created_at)?->getTimestamp())->take(6)->values()
                ->map(fn (CustomerReview $r) => [
                    'id' => $r->id,
                    'text' => $r->text,
                    'rating' => $r->rating,
                    'sentiment' => $r->sentiment,
                    'topics' => $r->topics ?? [],
                    'source' => $r->source,
                    'language' => $r->language,
                    'product' => $r->product_name,
                    'verified' => (bool) ($r->evidence['verified_purchase'] ?? false),
                    'date' => ($r->reviewed_at ?? $r->created_at)?->toIso8601String(),
                ]),
            'sync' => $zid ? [
                'status' => $run?->status ?? 'queued',
                'at' => ($run?->finished_at ?? $run?->started_at)?->toIso8601String(),
                'error' => $run?->status === 'failed' ? $run->error : null,
            ] : null,
        ];
    }

    public function refreshTrends(): RedirectResponse
    {
        $store = $this->current->require();

        DetectTrends::dispatch($store->id);
        Activity::log('market.trends_refreshed', $store);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('market.trends_refreshing')]);

        return back();
    }

    public function syncReviews(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        if (! CustomerReview::fromZid($store)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('market.reviews_need_zid')]);

            return back();
        }

        ImportZidReviews::dispatch($store->id, $request->user()->id);
        Activity::log('market.reviews_synced', $store);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('market.reviews_syncing')]);

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
}
