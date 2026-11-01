<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\CheckZidConnection;
use App\Jobs\SyncStoreData;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZidOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mojo.zid.client_id' => 'test-client', 'mojo.zid.client_secret' => 'test-secret']);
        Bus::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($owner, ['name' => 'Connection test']);
        $this->store->update([
            'platform_store_id' => '3278264', 'connection_status' => 'disconnected',
            'zid_authorization' => 'old-app-token', 'zid_access_token' => 'old-manager-token',
            'zid_refresh_token' => 'old-refresh-token',
        ]);
        $this->actingAs($owner)->withSession(['zid_oauth_state' => 'test-state']);
    }

    private function fakeZid(array $store, int $status = 200, array $tokenOverrides = []): void
    {
        Http::fake([
            'oauth.zid.sa/oauth/token' => Http::response(array_replace([
                'authorization' => 'new-app-token', 'access_token' => 'new-manager-token',
                'refresh_token' => null, 'expires_in' => 3600,
            ], $tokenOverrides)),
            'api.zid.sa/v1/managers/account/store' => Http::response($store, $status),
        ]);
    }

    private function receiveCallback(string $state = 'test-state')
    {
        return $this->get('/store/integrations/zid/callback?code=test-code&state='.$state);
    }

    public function test_oauth_verifies_the_actual_store_before_saving_tokens_and_importing(): void
    {
        $this->fakeZid(['store' => ['id' => 41960, 'title' => 'Real store']]);
        $this->receiveCallback()->assertRedirect(route('store.dashboard'));
        $this->store->refresh();
        $this->assertSame('41960', $this->store->platform_store_id);
        $this->assertSame('connected', $this->store->connection_status);
        $this->assertSame('new-app-token', $this->store->zid_authorization);
        $this->assertSame('new-manager-token', $this->store->zid_access_token);
        $this->assertNull($this->store->zid_refresh_token);
        $this->assertTrue($this->store->zid_token_expires_at->isFuture());
        $this->assertNotSame('new-manager-token', $this->store->getRawOriginal('zid_access_token'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'managers/account/store')
            && ! $request->hasHeader('Store-Id')
            && $request->hasHeader('Authorization', 'Bearer new-app-token')
            && $request->hasHeader('X-Manager-Token', 'new-manager-token'));
        Bus::assertChained([SyncStoreData::class, CheckZidConnection::class]);
        $this->assertDatabaseHas('activity_logs', ['store_id' => $this->store->id, 'action' => 'integration.connected']);
    }

    public function test_an_unverified_store_does_not_replace_credentials_or_start_an_import(): void
    {
        $this->fakeZid(['message' => ['code' => 'FORBIDDEN']], 403);
        $this->receiveCallback()->assertRedirect(route('store.settings.integration'));
        $this->store->refresh();
        $this->assertSame('3278264', $this->store->platform_store_id);
        $this->assertSame('old-app-token', $this->store->zid_authorization);
        $this->assertSame('old-manager-token', $this->store->zid_access_token);
        $this->assertSame('old-refresh-token', $this->store->zid_refresh_token);
        $this->assertSame('error', $this->store->connection_status);
        Bus::assertNothingDispatched();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'integration.connected']);
    }

    public function test_a_success_response_without_store_identity_is_not_a_connection(): void
    {
        $this->fakeZid(['user' => ['id' => 123]]);
        $this->receiveCallback()->assertRedirect(route('store.settings.integration'));
        $this->assertSame('error', $this->store->fresh()->connection_status);
        $this->assertSame('old-manager-token', $this->store->fresh()->zid_access_token);
        Bus::assertNothingDispatched();
    }

    public function test_missing_app_authorization_is_not_combined_with_old_credentials(): void
    {
        $this->fakeZid(['store' => ['id' => 41960]], tokenOverrides: ['authorization' => null]);
        $this->receiveCallback()->assertRedirect(route('store.settings.integration'));
        $this->assertSame('error', $this->store->fresh()->connection_status);
        $this->assertSame('old-manager-token', $this->store->fresh()->zid_access_token);
        Http::assertSentCount(1);
        Bus::assertNothingDispatched();
    }

    public function test_invalid_state_never_exchanges_the_authorization_code(): void
    {
        $this->receiveCallback('wrong-state')->assertRedirect(route('store.settings.integration'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame('disconnected', $this->store->fresh()->connection_status);
    }
}
