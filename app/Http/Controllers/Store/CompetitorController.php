<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\SocialPost;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\CompetitorScanState;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $selectedCompetitor = $selected ? $competitors->firstWhere('id', $selected) : null;

        return Inertia::render('store/competitors', [
            'competitors' => $competitors->map(fn (Competitor $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'platform' => $c->platform,
                'url' => $c->url,
                'handle' => $c->handle,
                'category' => $c->category,
                'notes' => $c->notes,
                'is_active' => $c->is_active,
                'is_social' => $c->isSocial(),
                'auto_scan' => $this->monitor->canScan($c),
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
            'selected' => $selectedCompetitor ? $this->details($selectedCompetitor) : null,
            'lastScan' => $store->syncRuns()->where('type', 'competitor_scan')->latest('id')->first(['status', 'stats', 'finished_at']),
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
            'posts' => SocialPost::query()->where('competitor_id', $c->id)->latest('posted_at')->limit(12)->get()
                ->map(fn (SocialPost $p) => [
                    'id' => $p->id,
                    'caption' => $p->caption,
                    'url' => $p->url,
                    'views' => $p->views,
                    'likes' => $p->likes,
                    'engagement_rate' => (float) $p->engagement_rate,
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

        $data['handle'] = isset($data['handle']) ? ltrim($data['handle'], '@') : null;
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
