<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeReviews;
use App\Jobs\DetectTrends;
use App\Models\CustomerReview;
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
 * rising in your sales and what is cooling; plus customer review insights.
 * Competitor posts and products in detail live on the competitor radar.
 */
class MarketController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(SentimentAnalyzer $sentiment, MarketTrends $trends): Response
    {
        $store = $this->current->require();

        $reviews = CustomerReview::query()->where('store_id', $store->id)->get();
        $analyzed = $reviews->whereNotNull('analyzed_at');

        return Inertia::render('store/market', [
            'board' => $trends->build($store),
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
}
