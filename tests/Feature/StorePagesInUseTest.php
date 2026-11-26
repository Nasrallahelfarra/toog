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
 * For now the store dashboard runs with a few pages only: the design
 * studio, the library, competitors, and the two team pages. The rest are
 * switched off for everyone. The owner can hand both team pages to a
 * member, who then manages the others without being able to touch their
 * own account or give out more than they hold.
 */
class StorePagesInUseTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'inertia.ssr.enabled' => false,
            'mojo.store_sections' => 'studio,library,competitors,team,team_report',
        ]);
        $this->owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Store']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staff(array $permissions = [], string $name = 'Staff'): User
    {
        $member = User::factory()->create(['name' => $name]);
        $this->store->members()->attach($member->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode($permissions)]);

        return $member;
    }

    /**
     * @return list<string>
     */
    private function pagesOf(User $member): array
    {
        return json_decode($this->store->members()->whereKey($member->id)->sole()->pivot->permissions, true);
    }

    private function fresh(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_the_owner_sees_only_the_pages_in_use_and_lands_on_the_first(): void
    {
        $this->actingAs($this->owner)->get('/store')->assertRedirect(route('store.studio.index'));

        $this->fresh();
        $this->actingAs($this->owner)->get('/store/studio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.owner', true)
                ->where('access.sections', ['studio', 'library', 'competitors', 'team', 'team_report'])
                ->where('access.home', '/store/studio'));

        foreach (['/store/catalog', '/store/performance', '/store/stagnant', '/store/evaluations', '/store/market', '/store/reports', '/store/settings/general'] as $path) {
            $this->fresh();
            $this->actingAs($this->owner)->get($path)->assertRedirect(route('store.studio.index'));
        }

        // The Zid connection is not a page that switches off.
        $this->fresh();
        $this->actingAs($this->owner)->get('/store/settings/integration')->assertOk();
    }

    public function test_the_access_form_offers_only_the_pages_in_use_and_keeps_the_others(): void
    {
        $member = $this->staff(['catalog', 'studio']);

        $this->actingAs($this->owner)->get('/store/team')
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/team/index')
                ->where('sections', ['decisions' => ['studio', 'library'], 'intelligence' => ['competitors'], 'management' => ['team', 'team_report']])
                ->where('members.0.permissions', ['catalog', 'studio']));

        // A page switched off cannot be given...
        $this->fresh();
        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", ['permissions' => ['studio', 'performance']])
            ->assertSessionHasErrors('permissions.1');

        // ...and one the member already had stays theirs for when it comes back.
        $this->fresh();
        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", ['permissions' => ['library']])->assertSessionHasNoErrors();
        $this->assertSame(['catalog', 'library'], $this->pagesOf($member));

        // Meanwhile the member opens only what is in use.
        $this->fresh();
        $this->actingAs($member)->get('/store/catalog')->assertRedirect(route('store.library.index'));
    }

    public function test_a_member_given_the_team_pages_manages_and_follows_the_others(): void
    {
        $manager = $this->staff(['studio', 'team', 'team_report'], 'Manager');
        $sara = $this->staff(['studio'], 'Sara');

        $this->actingAs($manager)->get('/store/team')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSetPolicy', false)
                ->where('canReport', true)
                // Only the pages the manager holds can be handed out.
                ->where('sections', ['decisions' => ['studio'], 'management' => ['team', 'team_report']]));

        $this->fresh();
        $this->actingAs($manager)->get('/store/team/report')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/team/report')->where('canManage', true)->has('report.members', 2));

        // Adds a member and gives them the report.
        $this->fresh();
        $this->actingAs($manager)->post('/store/team', [
            'name' => 'Noor', 'email' => 'noor@example.com', 'password' => '12345678', 'permissions' => ['studio', 'team_report'],
        ])->assertSessionHasNoErrors();
        $this->assertTrue(ActivityLog::query()->where('action', 'team.member_added')->where('user_id', $manager->id)->exists());

        // Changes another member's pages, but cannot give a page they lack.
        $this->fresh();
        $this->actingAs($manager)->put("/store/team/{$sara->id}/permissions", ['permissions' => ['studio', 'team_report']])->assertSessionHasNoErrors();
        $this->assertSame(['studio', 'team_report'], $this->pagesOf($sara));
        $this->fresh();
        $this->actingAs($manager)->put("/store/team/{$sara->id}/permissions", ['permissions' => ['library']])->assertSessionHasErrors('permissions.0');

        // Never their own account, and never the team-wide two-factor rule.
        $this->fresh();
        $this->actingAs($manager)->put("/store/team/{$manager->id}/permissions", ['permissions' => ['studio', 'library', 'team', 'team_report']])->assertForbidden();
        $this->fresh();
        $this->actingAs($manager)->post("/store/team/{$manager->id}/disable")->assertForbidden();
        $this->fresh();
        $this->actingAs($manager)->put('/store/team/security', ['require_two_factor' => true])->assertForbidden();
        $this->assertSame(['studio', 'team', 'team_report'], $this->pagesOf($manager));

        // Disables a colleague.
        $this->fresh();
        $this->actingAs($manager)->post("/store/team/{$sara->id}/disable")->assertSessionHasNoErrors();
        $this->assertSame(User::STATUS_DISABLED, $sara->fresh()->status);
    }

    public function test_the_report_alone_reads_without_managing(): void
    {
        $viewer = $this->staff(['team_report'], 'Viewer');
        $sara = $this->staff(['studio'], 'Sara');

        $this->actingAs($viewer)->get('/store')->assertRedirect(route('store.team.report'));

        $this->fresh();
        $this->actingAs($viewer)->get('/store/team/report')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

        $this->fresh();
        $this->actingAs($viewer)->get("/store/team/{$sara->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('pages', null));

        $this->fresh();
        $this->actingAs($viewer)->get('/store/team')->assertRedirect(route('store.team.report'));
        $this->fresh();
        $this->actingAs($viewer)->put("/store/team/{$sara->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->fresh();
        $this->actingAs($viewer)->post("/store/team/{$sara->id}/products", ['ids' => [1]])->assertForbidden();
    }

    public function test_a_member_with_none_of_the_pages_in_use_lands_on_their_products(): void
    {
        $member = $this->staff(['catalog']);

        $this->actingAs($member)->get('/store')->assertRedirect(route('store.my-products.index'));
    }
}
