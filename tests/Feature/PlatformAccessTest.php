<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two roles: the platform admin can see and act in every store; a store
 * owner only ever reaches their own store.
 */
class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithStore(string $name = 'Store'): array
    {
        $owner = User::factory()->create();
        $store = app(CreateStore::class)->handle($owner, ['name' => $name]);

        return [$owner, $store];
    }

    public function test_store_owners_cannot_open_the_admin_panel()
    {
        [$owner] = $this->ownerWithStore();

        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_store_owners_cannot_enter_someone_elses_store()
    {
        [$owner] = $this->ownerWithStore('Mine');
        [, $other] = $this->ownerWithStore('Theirs');

        $this->actingAs($owner)->post(route('stores.enter', $other))->assertForbidden();
    }

    public function test_admin_can_enter_any_store_and_the_visit_is_logged()
    {
        $admin = User::factory()->admin()->create();
        [, $store] = $this->ownerWithStore();

        $this->actingAs($admin)
            ->post(route('stores.enter', $store))
            ->assertRedirect(route('store.dashboard'));

        $this->actingAs($admin)->get(route('store.dashboard'))->assertOk();

        $this->assertTrue(ActivityLog::query()
            ->where('action', 'admin.store_entered')
            ->where('store_id', $store->id)
            ->where('acting_as_admin', true)
            ->exists());
    }

    public function test_admin_without_a_selected_store_is_sent_to_the_store_list()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('store.dashboard'))->assertRedirect(route('admin.stores.index'));
    }

    public function test_disabled_users_cannot_sign_in()
    {
        $user = User::factory()->disabled()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabling_a_signed_in_user_signs_them_out_on_the_next_request()
    {
        [$owner] = $this->ownerWithStore();
        $this->actingAs($owner);

        $owner->update(['status' => User::STATUS_DISABLED]);

        $this->get(route('store.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_suspended_store_is_closed_to_its_owner_but_open_to_the_admin()
    {
        [$owner, $store] = $this->ownerWithStore();
        $store->update(['status' => Store::STATUS_SUSPENDED]);

        $this->actingAs($owner)->get(route('store.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('store/suspended'));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('stores.enter', $store));
        $this->actingAs($admin)->get(route('store.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('store/dashboard'));
    }
}
