<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Two-factor sign-in for the store team: every member can turn it on, the
 * owner sees who has, can require it of the whole team, and resets it for
 * a member who lost their phone. The admin can reset it for anyone.
 */
class TeamTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Store']);
    }

    private function staff(bool $twoFactor = false, ?Store $store = null): User
    {
        $factory = User::factory();
        $member = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
        ($store ?? $this->store)->members()->attach($member->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode(['catalog'])]);

        return $member;
    }

    private function requireTwoFactor(): void
    {
        $this->store->update(['settings' => [...($this->store->settings ?? []), 'require_staff_two_factor' => true]]);
    }

    public function test_the_team_page_shows_who_signs_in_with_two_factor(): void
    {
        $on = $this->staff(twoFactor: true);
        $off = $this->staff();

        $this->actingAs($this->owner)->get('/store/team')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requireTwoFactor', false)
                ->has('members', 2)
                ->where('members', fn ($members) => collect($members)->firstWhere('id', $on->id)['two_factor'] === true
                    && collect($members)->firstWhere('id', $off->id)['two_factor'] === false));
    }

    public function test_the_owner_turns_the_team_requirement_on_and_off(): void
    {
        $this->actingAs($this->owner)->put('/store/team/security', ['require_two_factor' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->store->fresh()->requiresStaffTwoFactor());
        $this->assertTrue(ActivityLog::query()->where('action', 'team.two_factor_policy')->exists());

        $this->actingAs($this->owner)->put('/store/team/security', ['require_two_factor' => false]);
        $this->assertFalse($this->store->fresh()->requiresStaffTwoFactor());
    }

    public function test_staff_cannot_change_the_team_requirement(): void
    {
        $member = $this->staff();

        $this->actingAs($member)->put('/store/team/security', ['require_two_factor' => true])->assertForbidden();
        $this->assertFalse($this->store->fresh()->requiresStaffTwoFactor());
    }

    public function test_a_required_member_without_two_factor_is_held_at_their_security_page(): void
    {
        $this->requireTwoFactor();
        $member = $this->staff();

        $this->actingAs($member)->get('/store/catalog')->assertRedirect(route('security.edit'));
        $this->actingAs($member)->post('/store/my-products', ['ids' => [1]])->assertForbidden();

        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactorEnabled', false)
                ->where('twoFactorRequiredBy', ['Store']));
    }

    public function test_a_required_member_with_two_factor_works_as_before(): void
    {
        $this->requireTwoFactor();
        $member = $this->staff(twoFactor: true);

        $this->actingAs($member)->get('/store/catalog')->assertOk();
    }

    public function test_the_requirement_never_holds_the_owner_or_members_of_other_stores(): void
    {
        $this->requireTwoFactor();
        $elsewhere = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $outsider = $this->staff(store: $elsewhere);

        $this->actingAs($this->owner)->get('/store/catalog')->assertOk();
        // Each request resolves its own current store.
        $this->app->forgetScopedInstances();
        $this->actingAs($outsider)->get('/store/catalog')->assertOk();
    }

    public function test_the_owner_resets_two_factor_for_a_member_who_lost_their_phone(): void
    {
        $member = $this->staff(twoFactor: true);

        $this->actingAs($this->owner)->delete("/store/team/{$member->id}/two-factor")->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_recovery_codes);
        $this->assertNull($member->two_factor_confirmed_at);
        $this->assertTrue(ActivityLog::query()->where('action', 'team.member_two_factor_reset')->exists());
    }

    public function test_the_owner_resets_only_this_stores_staff(): void
    {
        $elsewhere = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $outsider = $this->staff(twoFactor: true, store: $elsewhere);
        $member = $this->staff(twoFactor: true);

        $this->actingAs($this->owner)->delete("/store/team/{$outsider->id}/two-factor")->assertNotFound();
        $this->actingAs($member)->delete("/store/team/{$member->id}/two-factor")->assertForbidden();
        $this->assertNotNull($outsider->fresh()->two_factor_secret);
        $this->assertNotNull($member->fresh()->two_factor_secret);
    }

    public function test_the_admin_resets_two_factor_for_an_owner(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->delete("/admin/users/{$this->owner->id}/two-factor")->assertSessionHasNoErrors();

        $this->assertNull($this->owner->fresh()->two_factor_secret);
        $this->assertTrue(ActivityLog::query()->where('action', 'admin.user_two_factor_reset')->exists());
    }

    public function test_the_same_key_stays_readable_to_add_another_device(): void
    {
        $member = $this->staff(twoFactor: true);

        // The security page opens the same QR code and key again after the
        // password is confirmed, so a second device can scan it.
        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->getJson('/user/two-factor-secret-key')
            ->assertOk()
            ->assertJson(['secretKey' => 'secret']);
        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->getJson('/user/two-factor-qr-code')
            ->assertOk()
            ->assertJsonStructure(['svg', 'url']);
    }
}
