<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Stores\CreateStore;
use App\Actions\Stores\DeleteStore;
use App\Http\Controllers\Admin\Concerns\AdminData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCreateRequest;
use App\Http\Requests\Admin\StoreUpdateRequest;
use App\Models\ActivityLog;
use App\Models\AiUsage;
use App\Models\Design;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    use AdminData;

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'connection']);
        $monthStart = now()->startOfMonth();

        $stores = Store::query()
            ->with('owner:id,name,email,phone,role,status')
            ->withCount(['products', 'evaluations'])
            ->withSum(['aiUsages as month_cost' => fn ($q) => $q->where('created_at', '>=', $monthStart)], 'cost')
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn (Builder $o) => $o->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['connection'] ?? null, fn (Builder $q, string $c) => $q->where('connection_status', $c))
            ->latest()
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Store $s) => $this->storeRow($s));

        return Inertia::render('admin/stores/index', [
            'stores' => $stores,
            'filters' => $filters,
            'counts' => [
                'all' => Store::query()->count(),
                'active' => Store::query()->where('status', Store::STATUS_ACTIVE)->count(),
                'suspended' => Store::query()->where('status', Store::STATUS_SUSPENDED)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/stores/create', [
            'owners' => User::query()
                ->where('role', User::ROLE_STORE_OWNER)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(StoreCreateRequest $request, CreateStore $creator): RedirectResponse
    {
        $data = $request->validated();

        $store = DB::transaction(function () use ($data, $creator) {
            $owner = $data['owner_mode'] === 'existing'
                ? User::query()->where('email', $data['owner_email'])->firstOrFail()
                : User::query()->create([
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    'phone' => $data['owner_phone'] ?? null,
                    'password' => $data['owner_password'],
                    'role' => User::ROLE_STORE_OWNER,
                    'locale' => 'ar',
                    'email_verified_at' => now(),
                ]);

            $store = $creator->handle($owner, [
                'name' => $data['name'],
                'domain' => $data['domain'] ?? null,
                'category' => $data['category'] ?? null,
                'platform' => $data['platform'] ?? 'zid',
            ]);

            $store->update(['ai_monthly_budget' => $data['ai_monthly_budget'] ?? null]);

            return $store;
        });

        Activity::log('admin.store_created', $store, [
            'owner' => $store->owner->email,
            'new_owner' => $data['owner_mode'] === 'new',
        ], $store->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.store_created')]);

        return to_route('admin.stores.show', $store);
    }

    public function show(Request $request, Store $store): Response
    {
        $store->load('owner:id,name,email,phone,role,status,last_login_at');
        $monthStart = now()->startOfMonth();
        $since = now()->subMonths(11)->startOfMonth();

        $revenue = ProductSale::query()
            ->where('store_id', $store->id)
            ->where('period', '>=', $since)
            ->selectRaw('period, SUM(revenue) as revenue, SUM(units) as units, SUM(orders) as orders')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => [
                'period' => substr((string) $r->period, 0, 7),
                'revenue' => round((float) $r->revenue),
                'units' => (int) $r->units,
                'orders' => (int) $r->orders,
            ]);

        $usage = AiUsage::query()->where('store_id', $store->id)->where('created_at', '>=', now()->subDays(29)->startOfDay());

        return Inertia::render('admin/stores/show', [
            'store' => [
                ...$this->storeRow($store->loadCount(['products', 'evaluations'])->loadSum(
                    ['aiUsages as month_cost' => fn ($q) => $q->where('created_at', '>=', $monthStart)], 'cost',
                )),
                'platform_store_id' => $store->platform_store_id,
                'suspended_reason' => $store->suspended_reason,
                'sync_frequency' => $store->sync_frequency,
                'logo_url' => $store->logo_path ? Storage::disk('public')->url($store->logo_path) : null,
                'settings' => [
                    'accept_threshold' => (int) $store->setting('accept_threshold'),
                    'reject_threshold' => (int) $store->setting('reject_threshold'),
                    'proven_ratio' => (int) $store->setting('proven_ratio'),
                ],
                'has_brand_guide' => ! empty($store->brand_guide),
            ],
            'overview' => [
                'performance' => Product::query()->where('store_id', $store->id)
                    ->selectRaw("COALESCE(performance, 'unclassified') as class, COUNT(*) as total")
                    ->groupBy('class')->pluck('total', 'class'),
                'recommendations' => Evaluation::query()->where('store_id', $store->id)->whereNotNull('recommendation')
                    ->selectRaw('recommendation, COUNT(*) as total')->groupBy('recommendation')->pluck('total', 'recommendation'),
                'decisions' => Evaluation::query()->where('store_id', $store->id)->whereNotNull('final_decision')
                    ->selectRaw('final_decision, COUNT(*) as total')->groupBy('final_decision')->pluck('total', 'final_decision'),
                'designs' => Design::query()->where('store_id', $store->id)
                    ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'avg_score' => round((float) Evaluation::query()->where('store_id', $store->id)->avg('score')),
                'revenue' => $revenue,
                'sync_runs' => SyncRun::query()->where('store_id', $store->id)->latest()->limit(5)
                    ->get(['id', 'type', 'status', 'error', 'started_at', 'finished_at']),
            ],
            'ai' => [
                'daily' => $this->dailyAiCost(now()->subDays(29)->startOfDay(), $store->id),
                'by_operation' => (clone $usage)->selectRaw('operation, SUM(cost) as cost, COUNT(*) as calls, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures')
                    ->groupBy('operation')->orderByDesc('cost')->get(),
                'by_model' => (clone $usage)->selectRaw('provider, model, SUM(cost) as cost, COUNT(*) as calls, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                    ->groupBy('provider', 'model')->orderByDesc('cost')->get(),
                'recent' => AiUsage::query()->where('store_id', $store->id)->with('user:id,name')->latest('created_at')->limit(15)
                    ->get(['id', 'user_id', 'provider', 'model', 'operation', 'input_tokens', 'output_tokens', 'images', 'cost', 'success', 'error', 'latency_ms', 'created_at']),
                'total_30d' => round((float) (clone $usage)->sum('cost'), 4),
                'calls_30d' => (clone $usage)->count(),
            ],
            'members' => $store->members()->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role,
                'member_role' => $u->pivot->role,
                'status' => $u->status,
                'disabled_reason' => $u->disabled_reason,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'is_owner' => $u->id === $store->owner_id,
            ]),
            'activity' => $this->activityPage(
                ActivityLog::query()->where('store_id', $store->id)->with(['user:id,name,email', 'store:id,name'])
                    ->latest('created_at')->paginate(10, ['*'], 'activity_page')->withQueryString(),
            ),
            'tab' => $request->query('tab', 'overview'),
        ]);
    }

    public function update(StoreUpdateRequest $request, Store $store): RedirectResponse
    {
        $data = $request->validated();
        $before = $this->snapshot($store);

        $store->update([
            'name' => $data['name'],
            'domain' => $data['domain'] ?? null,
            'category' => $data['category'] ?? null,
            'sync_frequency' => $data['sync_frequency'],
            'ai_monthly_budget' => $data['ai_monthly_budget'] ?? null,
            'settings' => array_merge($store->settings ?? [], [
                'accept_threshold' => (int) $data['accept_threshold'],
                'reject_threshold' => (int) $data['reject_threshold'],
                'proven_ratio' => (int) $data['proven_ratio'],
            ]),
        ]);

        $after = $this->snapshot($store->fresh());
        $changes = collect($after)
            ->filter(fn ($value, $key) => $before[$key] != $value)
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->all();

        Activity::log('admin.store_updated', $store, ['changes' => $changes], $store->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return to_route('admin.stores.show', [$store, 'tab' => 'settings']);
    }

    /**
     * Permanently delete a store and all of its data. The admin retypes the
     * store name to confirm, since nothing can be restored afterwards.
     */
    public function destroy(Request $request, Store $store, DeleteStore $delete): RedirectResponse
    {
        if (trim((string) $request->input('confirm')) !== trim($store->name)) {
            throw ValidationException::withMessages(['confirm' => __('mojo.confirm_name_mismatch')]);
        }

        Activity::log('admin.store_deleted', null, ['store' => $store->name, 'owner' => $store->owner?->email]);
        $delete->handle($store);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.store_deleted', ['name' => $store->name])]);

        return to_route('admin.stores.index');
    }

    public function suspend(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $store->update(['status' => Store::STATUS_SUSPENDED, 'suspended_reason' => $data['reason']]);

        $store->owner?->notify(new PlatformNotification(
            kind: 'system',
            titleAr: "تم إيقاف متجر «{$store->name}»",
            titleEn: "Store “{$store->name}” has been suspended",
            bodyAr: $data['reason'],
            bodyEn: $data['reason'],
            storeId: $store->id,
            level: 'danger',
        ));

        Activity::log('admin.store_suspended', $store, ['reason' => $data['reason']], $store->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.store_suspended')]);

        return back();
    }

    public function activate(Store $store): RedirectResponse
    {
        $store->update(['status' => Store::STATUS_ACTIVE, 'suspended_reason' => null]);

        $store->owner?->notify(new PlatformNotification(
            kind: 'system',
            titleAr: "تمت إعادة تفعيل متجر «{$store->name}»",
            titleEn: "Store “{$store->name}” is active again",
            storeId: $store->id,
            level: 'success',
        ));

        Activity::log('admin.store_activated', $store, [], $store->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.store_activated')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function storeRow(Store $s): array
    {
        $budget = $s->ai_monthly_budget !== null ? (float) $s->ai_monthly_budget : null;
        $cost = round((float) ($s->month_cost ?? 0), 4);

        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'domain' => $s->domain,
            'category' => $s->category,
            'platform' => $s->platform,
            'status' => $s->status,
            'connection_status' => $s->connection_status,
            'owner' => $s->owner ? ['id' => $s->owner->id, 'name' => $s->owner->name, 'email' => $s->owner->email, 'phone' => $s->owner->phone, 'role' => $s->owner->role, 'status' => $s->owner->status] : null,
            'products_count' => $s->products_count ?? 0,
            'evaluations_count' => $s->evaluations_count ?? 0,
            'month_cost' => $cost,
            'ai_monthly_budget' => $budget,
            'budget_usage' => $budget ? (int) round($cost / $budget * 100) : null,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Store $store): array
    {
        return [
            'name' => $store->name,
            'domain' => $store->domain,
            'category' => $store->category,
            'sync_frequency' => $store->sync_frequency,
            'ai_monthly_budget' => $store->ai_monthly_budget !== null ? (float) $store->ai_monthly_budget : null,
            'accept_threshold' => (int) $store->setting('accept_threshold'),
            'reject_threshold' => (int) $store->setting('reject_threshold'),
            'proven_ratio' => (int) $store->setting('proven_ratio'),
        ];
    }
}
