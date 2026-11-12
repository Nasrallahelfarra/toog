<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\SocialPost;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\CompetitorRadar;
use App\Services\Market\CompetitorScanState;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Competitor list management (FR-2.1) and on-demand scans (FR-2.2).
 */
class CompetitorController extends Controller
{
    public function __construct(
        private readonly CurrentStore $current,
        private readonly CompetitorMonitor $monitor,
        private readonly CompetitorScanState $scans,
    ) {}

    public function index(Request $request): Response
    {
        $store = $this->current->require();

        foreach ($store->competitors()->pluck('id') as $id) {
            $this->scans->expire($id);
        }
        $competitors = $store->competitors()
            ->with('latestScan')
            ->withCount(['products', 'posts'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $selected = $request->integer('competitor') ?: null;
        $days = in_array($request->integer('days'), CompetitorRadar::WINDOWS, true) ? $request->integer('days') : 30;
        $selectedCompetitor = $selected ? $competitors->firstWhere('id', $selected) : null;

        return Inertia::render('store/competitors', [
            'competitors' => $competitors->map(fn (Competitor $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'platform' => $c->platform,
                'url' => $c->url,
                'profile_url' => $c->profileUrl(),
                'handle' => $c->handle,
                'category' => $c->category,
                'notes' => $c->notes,
                'is_active' => $c->is_active,
                'is_social' => $c->isSocial(),
                'auto_scan' => $this->monitor->canScan($c),
                'scan_blocker' => $this->monitor->blocker($c),
                'products_count' => $c->products_count,
                'posts_count' => $c->posts_count,
                'last_checked_at' => $c->last_checked_at?->toIso8601String(),
                'last_error' => $c->last_error,
                'scan' => $c->latestScan,
            ]),
            'stats' => [
                'total' => $competitors->count(),
                'active' => $competitors->where('is_active', true)->count(),
                'products' => $competitors->sum('products_count'),
                'posts' => $competitors->sum('posts_count'),
            ],
            'platforms' => [...Competitor::STORE_PLATFORMS, ...Competitor::SOCIAL_PLATFORMS],
            'categories' => $store->products()->whereNotNull('category')->distinct()->pluck('category'),
            'socialPosts' => ['limit' => $store->socialPostsLimit(), 'max' => (int) config('mojo.apify.max_posts_limit')],
            'selected' => $selectedCompetitor ? $this->details($selectedCompetitor) : null,
            'lastScan' => $store->syncRuns()->where('type', 'competitor_scan')->latest('id')->first(['status', 'stats', 'finished_at']),
            // The radar answers "what earns them engagement, and what should
            // I add?"; managing the list is its own tab.
            'tab' => in_array($request->query('tab'), ['radar', 'products', 'accounts'], true) ? $request->query('tab') : 'radar',
            'days' => $days,
            'radar' => Inertia::defer(fn () => app(CompetitorRadar::class)->build($store, $days)),
            'reading' => fn () => CompetitorRadarController::reading($store->id, $days),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $competitor = $store->competitors()->create($this->validated($request));

        Activity::log('competitor.created', $competitor, ['name' => $competitor->name, 'platform' => $competitor->platform]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.competitor_saved')]);

        return to_route('store.competitors.index', ['competitor' => $competitor->id]);
    }

    public function update(Request $request, Competitor $competitor): RedirectResponse
    {
        $this->authorizeCompetitor($competitor);
        $this->ensureNotScanning($competitor);

        $competitor->update($this->validated($request));

        Activity::log('competitor.updated', $competitor, ['name' => $competitor->name, 'changes' => array_keys($competitor->getChanges())]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.competitor_saved')]);

        return back();
    }

    public function destroy(Competitor $competitor): RedirectResponse
    {
        $this->authorizeCompetitor($competitor);
        $this->ensureNotScanning($competitor);

        Activity::log('competitor.deleted', $competitor, ['name' => $competitor->name]);
        $competitor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.competitor_deleted')]);

        return to_route('store.competitors.index');
    }

    /**
     * Queue a scan and open its progress panel without waiting for the crawl.
     */
    public function scan(Competitor $competitor): RedirectResponse
    {
        $this->authorizeCompetitor($competitor);

        $scan = $this->scans->enqueue($competitor);
        Activity::log('competitor.scan_requested', $competitor, ['scan_id' => $scan->id]);

        return to_route('store.competitors.index', ['competitor' => $competitor->id]);
    }

    public function scanAll(): RedirectResponse
    {
        $store = $this->current->require();

        $this->scans->enqueueStore($store);
        Activity::log('competitor.scan_all', $store);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('market.scan_queued')]);

        return back();
    }

    /** How many of each social account's latest posts a scan reads. */
    public function updateSocialSettings(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $data = $request->validate([
            'posts_limit' => ['required', 'integer', 'min:1', 'max:'.(int) config('mojo.apify.max_posts_limit')],
        ]);

        $before = $store->socialPostsLimit();
        $store->update(['settings' => [...($store->settings ?? []), 'social_posts_limit' => (int) $data['posts_limit']]]);
        if ($before !== $store->socialPostsLimit()) {
            Activity::log('settings.updated', $store, ['section' => 'competitors',
                'changes' => ['social_posts_limit' => ['from' => $before, 'to' => $store->socialPostsLimit()]]]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Competitor $c): array
    {
        return [
            'id' => $c->id,
            'analysis' => $c->analysis,
            'products' => CompetitorProduct::query()->where('competitor_id', $c->id)
                ->when($c->analysis['crawl']['observed_at'] ?? null, fn ($q, $time) => $q->where('evidence->observed_at', $time))
                ->orderByDesc('is_best_seller')->latest('first_seen_at')->limit(160)->get()
                ->map(fn (CompetitorProduct $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category' => $p->category,
                    'price' => $p->price !== null ? (float) $p->price : null,
                    'previous_price' => $p->previous_price !== null ? (float) $p->previous_price : null,
                    'image_url' => $p->image_url,
                    'url' => $p->url,
                    'is_best_seller' => $p->is_best_seller,
                    'evidence' => $p->evidence,
                    'colors' => $p->features['colors'] ?? [],
                    'pattern' => $p->features['pattern'] ?? null,
                    'first_seen_at' => $p->first_seen_at?->toIso8601String(),
                ]),
            // A social report covers the posts read in its scan, like products above.
            'posts' => SocialPost::query()->where('competitor_id', $c->id)
                ->when(($c->analysis['kind'] ?? null) === 'social' ? $c->analysis['crawl']['observed_at'] ?? null : null,
                    fn ($q, $time) => $q->where('evidence->observed_at', $time))
                ->latest('posted_at')->limit(60)->get()
                ->map(fn (SocialPost $p) => [
                    'id' => $p->id,
                    'platform' => $p->platform,
                    'caption' => $p->caption,
                    'url' => $p->url,
                    // The copy kept at scan time outlives the platform's expiring link.
                    'thumbnail_url' => filled($p->evidence['image_path'] ?? null)
                        ? Storage::disk('public')->url($p->evidence['image_path']) : $p->thumbnail_url,
                    'views' => $p->views,
                    'likes' => $p->likes,
                    'comments' => $p->comments,
                    'shares' => $p->shares,
                    'engagement_rate' => $p->measuredEngagement(),
                    'engagement_basis' => $p->evidence['engagement_basis'] ?? null,
                    'type' => $p->evidence['type'] ?? null,
                    'likes_hidden' => (bool) ($p->evidence['likes_hidden'] ?? false),
                    'posted_at' => $p->posted_at?->toIso8601String(),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $social = in_array($request->input('platform'), Competitor::SOCIAL_PLATFORMS, true);

        $data = $request->validate([
            'name' => [$social ? 'required' : 'nullable', 'string', 'max:120'],
            'platform' => ['required', Rule::in([...Competitor::STORE_PLATFORMS, ...Competitor::SOCIAL_PLATFORMS])],
            'url' => [$social ? 'nullable' : 'required', 'nullable', 'url:http,https', 'max:255'],
            'handle' => [$social ? 'required' : 'nullable', 'nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $data['handle'] = Competitor::normalizeHandle($data['handle'] ?? null);
        $data['name'] = ($data['name'] ?? null) ?: (string) parse_url($data['url'], PHP_URL_HOST);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function authorizeCompetitor(Competitor $competitor): void
    {
        abort_unless($competitor->store_id === $this->current->id(), 404);
    }

    private function ensureNotScanning(Competitor $competitor): void
    {
        $this->scans->expire($competitor->id);
        if ($competitor->latestScan?->isActive()) {
            throw ValidationException::withMessages(['scan' => __('market.scan_in_progress')]);
        }
    }
}
