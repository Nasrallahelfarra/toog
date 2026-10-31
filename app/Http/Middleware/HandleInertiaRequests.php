<?php

namespace App\Http\Middleware;

use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Support\CurrentStore;
use App\Support\Vocabulary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $current = app(CurrentStore::class);
        $store = $user ? $current->get() : null;
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'auth' => [
                'user' => $user,
            ],
            'currentStore' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'platform' => $store->platform,
                'connection_status' => $store->connection_status,
                'logo_url' => $store->logo_path ? Storage::disk('public')->url($store->logo_path) : null,
            ] : null,
            'stores' => $user instanceof User && ! $user->isAdmin()
                ? $user->stores()->get(['stores.id', 'stores.name'])->map->only(['id', 'name'])
                : [],
            'actingAsAdmin' => $user instanceof User && $current->actingAsAdmin(),
            // Same scope as the notifications page: this store plus platform-wide.
            'unreadNotifications' => fn () => $user?->unreadNotifications()
                ->when($store, fn ($q) => $q->where(fn ($q) => $q->whereNull('data->store_id')->orWhere('data->store_id', $store->id)))
                ->count() ?? 0,
            'aiLive' => fn () => app(Ai::class)->isLive(),
            // Last Gemini error if the most recent call failed (e.g. billing),
            // so the header can say so instead of showing "connected".
            'aiError' => fn () => $user && app(Ai::class)->isLive() ? $this->recentAiError() : null,
            'vocabulary' => fn () => $user ? Vocabulary::forFrontend() : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function recentAiError(): ?string
    {
        $last = AiUsage::query()
            ->where('provider', 'gemini')
            ->where('created_at', '>=', now()->subHours(6))
            ->where(fn ($q) => $q->whereNull('error')->orWhere('error', '!=', 'budget_exceeded'))
            ->latest('id')
            ->first(['success', 'error']);

        return $last && ! $last->success ? Str::limit((string) $last->error, 200) : null;
    }
}
