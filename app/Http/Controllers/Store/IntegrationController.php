<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SyncRun;
use App\Services\Zid\ConnectionCheck;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidSyncState;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Zid connection (OAuth), sync settings and sync history (FR-1.1, FR-1.6).
 */
class IntegrationController extends Controller
{
    private const STATE_KEY = 'zid_oauth_state';

    public function __construct(private readonly CurrentStore $current) {}

    public function show(Request $request): Response
    {
        $store = $this->current->require();
        $sync = app(ZidSyncState::class)->latest($store);

        return Inertia::render('store/settings/integration', [
            'sync' => $sync,
            'zid' => [
                'configured' => ZidClient::configured(),
                // Setting up the Zid app (.env credentials) is the platform admin's job.
                'can_configure' => $request->user()->isAdmin(),
                'status' => $store->connection_status,
                'platform' => $store->platform,
                'platform_store_id' => $store->platform_store_id,
                'has_credentials' => filled($store->zid_access_token),
                'token_expires_at' => $store->zid_token_expires_at?->toIso8601String(),
                'last_synced_at' => $store->last_synced_at?->toIso8601String(),
                'sync_frequency' => $store->sync_frequency,
                'redirect_uri' => config('mojo.zid.redirect_uri'),
            ],
            'counts' => [
                'products' => Product::query()->where('store_id', $store->id)->count(),
                'sales_rows' => ProductSale::query()->where('store_id', $store->id)->count(),
            ],
            'check' => ConnectionCheck::latest($store),
            'runs' => SyncRun::query()
                ->where('store_id', $store->id)
                ->where('type', '!=', 'connection_check')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (SyncRun $r) => [
                    'id' => $r->id,
                    'type' => $r->type,
                    'status' => $r->status,
                    'stats' => $r->stats ?? [],
                    'error' => $r->error,
                    'started_at' => ($r->started_at ?? $r->created_at)?->toIso8601String(),
                    'duration' => $r->started_at && $r->finished_at ? (int) $r->started_at->diffInSeconds($r->finished_at) : null,
                ]),
            'importReport' => $request->session()->get('importReport'),
        ]);
    }

    /**
     * Send the merchant to Zid to authorise the app.
     */
    public function redirect(Request $request): SymfonyResponse
    {
        if (! ZidClient::configured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.zid_not_configured')]);

            return back();
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return Inertia::location(ZidClient::authorizeUrl($state));
    }

    /**
     * Zid redirects back here with ?code=…&state=…
     */
    public function callback(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $expected = $request->session()->pull(self::STATE_KEY);

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.zid_state_mismatch')]);

            return to_route('store.settings.integration');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.zid_failed', ['error' => $request->query('error_description', $request->query('error', 'no code'))])]);

            return to_route('store.settings.integration');
        }

        try {
            $tokens = ZidClient::token([
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
            ]);
            if (! is_string($tokens['authorization']) || blank($tokens['authorization']) || ! is_string($tokens['access_token']) || blank($tokens['access_token'])) {
                throw new RuntimeException('Zid did not return both required tokens. Reactivate the app and try again.');
            }
            // Verify the new credentials without overwriting the previous connection.
            // The manager profile no longer guarantees a nested store object.
            $pending = clone $store;
            $pending->forceFill(['zid_authorization' => $tokens['authorization'], 'zid_access_token' => $tokens['access_token']]);
            $profile = (new ZidClient($pending))->storeProfile();
            // Save identity and the whole new token set together; never reuse an old
            // store's refresh token when the OAuth response omits one.
            $store->forceFill(['platform_store_id' => (string) $profile['store']['id'], 'zid_refresh_token' => null]);
            ZidClient::storeTokens($store, $tokens);
        } catch (Throwable $e) {
            report($e);
            $store->forceFill(['connection_status' => 'error'])->save();
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.zid_failed', ['error' => $e->getMessage()])]);

            return to_route('store.settings.integration');
        }

        Activity::log('integration.connected', $store, ['platform' => 'zid']);

        // First import, then verify the whole chain (token, permissions,
        // order lines, imported data, analysis) and tell the owner.
        app(ZidSyncState::class)->enqueue($store, $request->user()->id, checkAfter: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.zid_connected')]);

        // First connection: go to the dashboard, which follows the first
        // import live; later reconnections return to the settings page.
        return Product::query()->where('store_id', $store->id)->exists()
            ? to_route('store.settings.integration')
            : to_route('store.dashboard');
    }

    /**
     * Run the connection check now and show the result on the page.
     */
    public function check(ConnectionCheck $check): RedirectResponse
    {
        $store = $this->current->require();
        $result = $check->run($store);

        Activity::log('integration.checked', $store, ['ok' => $result['ok'], 'counts' => $result['counts']]);
        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => __('store.zid_check_ok')]
            : ['type' => 'error', 'message' => __('store.zid_check_failed', ['count' => $result['counts']['fail']])]);

        return back();
    }

    public function disconnect(): RedirectResponse
    {
        $store = $this->current->require();

        $store->forceFill([
            'zid_authorization' => null,
            'zid_access_token' => null,
            'zid_refresh_token' => null,
            'zid_token_expires_at' => null,
            'connection_status' => 'disconnected',
        ])->save();

        Activity::log('integration.disconnected', $store, ['platform' => 'zid']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.zid_disconnected')]);

        return back();
    }

    public function sync(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        if (blank($store->zid_authorization) || blank($store->zid_access_token) || blank($store->platform_store_id)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.sync_needs_connection')]);

            return back();
        }

        $run = app(ZidSyncState::class)->enqueue($store, $request->user()->id);
        Activity::log('data.sync_requested', $store);
        Inertia::flash('toast', ['type' => $run->status === 'failed' ? 'error' : 'success', 'message' => $run->status === 'failed' ? __('store.sync_start_failed') : __('mojo.sync_started')]);

        return back();
    }

    public function updateFrequency(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $data = $request->validate(['sync_frequency' => ['required', 'in:daily,weekly']]);

        if ($store->sync_frequency !== $data['sync_frequency']) {
            Activity::log('settings.updated', $store, [
                'section' => 'integration',
                'changes' => ['sync_frequency' => ['from' => $store->sync_frequency, 'to' => $data['sync_frequency']]],
            ]);
            $store->update($data);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }
}
