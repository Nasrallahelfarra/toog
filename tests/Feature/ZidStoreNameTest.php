<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\RefreshZidStoreName;
use App\Jobs\SyncStoreData;
use App\Models\Store;
use App\Models\User;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidSyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A store connected to Zid carries its Zid name: on connecting, after each
 * sync and on connection checks; the settings page does not edit it.
 */
class ZidStoreNameTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'دار لمى للعبايات']);
        $this->store->update([
            'platform' => 'zid', 'platform_store_id' => '41960', 'connection_status' => 'connected',
            'zid_authorization' => 'app-token', 'zid_access_token' => 'manager-token',
        ]);
    }

    private function fakeProfile(int $id, mixed $title): void
    {
        Http::fake(['api.zid.sa/v1/managers/account/store*' => Http::response(['store' => ['id' => $id, 'title' => $title]])]);
    }

    public function test_the_name_is_read_from_the_store_profile(): void
    {
        $this->assertSame('متجر لمى', ZidClient::storeName(['store' => ['title' => "  متجر\n لمى "]]));
        $this->assertSame('متجر لمى', ZidClient::storeName(['store' => ['title' => ['ar' => 'متجر لمى', 'en' => 'Lama Store']]]));
        $this->assertNull(ZidClient::storeName(['store' => ['title' => '']]));
        $this->assertNull(ZidClient::storeName([]));
    }

    public function test_a_sync_renames_the_store_after_its_zid_name(): void
    {
        $this->fakeProfile(41960, 'متجر لمى');

        (new RefreshZidStoreName($this->store->id))->handle();

        $this->assertSame('متجر لمى', $this->store->fresh()->name);
        $this->assertDatabaseHas('activity_logs', ['store_id' => $this->store->id, 'action' => 'integration.store_renamed']);
    }

    public function test_another_zid_stores_name_is_never_taken(): void
    {
        $this->fakeProfile(99999, 'متجر آخر');

        (new RefreshZidStoreName($this->store->id))->handle();

        $this->assertSame('دار لمى للعبايات', $this->store->fresh()->name);
    }

    public function test_every_scheduled_sync_is_followed_by_the_name_refresh(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        app(ZidSyncState::class)->enqueue($this->store);

        Bus::assertChained([SyncStoreData::class, RefreshZidStoreName::class]);
    }

    public function test_settings_do_not_edit_a_zid_stores_name(): void
    {
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/settings/general')
            ->assertInertia(fn (Assert $page) => $page->where('store.name_from_zid', true));
        $this->post('/store/settings/general', ['name' => 'اسم آخر', 'category' => 'أزياء محتشمة'])->assertRedirect();
        $this->assertSame('دار لمى للعبايات', $this->store->fresh()->name);
        $this->assertSame('أزياء محتشمة', $this->store->fresh()->category);
    }

    public function test_a_store_without_zid_names_itself(): void
    {
        $this->store->update(['zid_access_token' => null, 'zid_authorization' => null, 'connection_status' => 'disconnected']);

        $this->actingAs($this->store->owner)->post('/store/settings/general', ['name' => 'اسم آخر'])->assertRedirect();
        $this->assertSame('اسم آخر', $this->store->fresh()->name);
    }
}
