<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_store_owners_are_sent_to_their_store_dashboard()
    {
        $user = User::factory()->create();
        app(CreateStore::class)->handle($user, ['name' => 'Test store']);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('store.dashboard'));
        $this->actingAs($user)->get(route('store.dashboard'))->assertOk();
    }

    public function test_a_store_without_data_gets_the_connect_screen_first()
    {
        $user = User::factory()->create();
        $store = app(CreateStore::class)->handle($user, ['name' => 'Test store']);

        $this->actingAs($user)->get(route('store.dashboard'))
            ->assertInertia(fn ($page) => $page->where('setup.state', 'connect')->where('setup.connection_status', 'disconnected'));

        // Once the store is connected and the first import is running…
        $store->update(['connection_status' => 'connected']);
        SyncRun::query()->create(['store_id' => $store->id, 'type' => 'zid_sync', 'status' => 'running', 'started_at' => now()]);
        $this->actingAs($user)->get(route('store.dashboard'))->assertInertia(fn ($page) => $page->where('setup.state', 'syncing'));

        // …and when products arrive the normal dashboard takes over.
        Product::query()->create(['store_id' => $store->id, 'name' => 'Abaya', 'category' => 'abayas', 'price' => 300]);
        $this->actingAs($user)->get(route('store.dashboard'))->assertInertia(fn ($page) => $page->where('setup', null));
    }

    public function test_admins_are_sent_to_the_platform_dashboard()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
    }
}
