<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Actions\Stores\DeleteStore;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Team\ProductAssignments;
use App\Services\Team\TeamReport;
use App\Support\StoreSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The store team: the owner adds staff with a limited set of pages; each
 * staff member takes the products they added, no product can be held by
 * two members, and the owner compares how each member's products sell.
 */
class StoreTeamTest extends TestCase
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

    /**
     * @param  list<string>  $permissions
     */
    private function staff(array $permissions = [], ?Store $store = null, string $name = 'Staff'): User
    {
        $member = User::factory()->create(['name' => $name]);
        ($store ?? $this->store)->members()->attach($member->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode($permissions)]);

        return $member;
    }

    /**
     * The pages stored for a member, as the database holds them.
     *
     * @return list<string>
     */
    private function pagesOf(User $member, ?Store $store = null): array
    {
        return json_decode(($store ?? $this->store)->members()->whereKey($member->id)->sole()->pivot->permissions, true);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return $this->store->products()->create(['name' => $name, 'external_id' => $name, 'price' => 100, ...$attributes]);
    }

    private function sale(Product $product, int $monthsBack, int $units, int $price = 100): void
    {
        ProductSale::query()->create([
            'store_id' => $this->store->id, 'product_id' => $product->id,
            'period' => now()->startOfMonth()->subMonths($monthsBack),
            'orders' => $units, 'units' => $units, 'revenue' => $units * $price,
        ]);
    }

    /**
     * A member takes products, outside any request. The service logs the
     * action, which resolves the request-scoped current store; forget it so
     * the requests that follow resolve their own.
     *
     * @param  list<int>  $ids
     */
    private function claim(User $member, array $ids): void
    {
        app(ProductAssignments::class)->claim($this->store, $member, $ids);
        $this->app->forgetScopedInstances();
    }

    // ---- Staff accounts -------------------------------------------------

    public function test_the_owner_adds_a_staff_member_with_chosen_pages(): void
    {
        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'secret-pass-1',
            'permissions' => ['catalog', 'studio', 'integration', 'not-a-page'],
        ])->assertSessionHasErrors(['permissions.2', 'permissions.3']);

        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'secret-pass-1',
            'permissions' => ['studio', 'catalog'],
        ])->assertSessionHasNoErrors();

        $sara = User::query()->where('email', 'sara@example.com')->firstOrFail();
        $this->assertNotNull($sara->email_verified_at);
        $this->assertFalse($sara->isAdmin());

        $membership = $this->store->members()->whereKey($sara->id)->firstOrFail()->pivot;
        $this->assertSame(Store::ROLE_STAFF, $membership->role);
        // Saved in the sidebar's order, whatever order they were ticked in.
        $this->assertSame(['catalog', 'studio'], json_decode($membership->permissions, true));
        $this->assertTrue(ActivityLog::query()->where('action', 'team.member_added')->where('store_id', $this->store->id)->exists());
    }

    public function test_a_staff_password_can_be_eight_digits_even_under_the_strict_platform_rule(): void
    {
        // Production asks owners and admins for a long mixed password.
        Password::defaults(fn () => Password::min(12)->mixedCase()->letters()->numbers()->symbols());

        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => '1234567', 'permissions' => [],
        ])->assertSessionHasErrors('password');

        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => '12345678', 'permissions' => [],
        ])->assertSessionHasNoErrors();

        $sara = User::query()->where('email', 'sara@example.com')->firstOrFail();
        $this->assertTrue(password_verify('12345678', $sara->password));

        $this->actingAs($this->owner)->put("/store/team/{$sara->id}/password", [
            'password' => '87654321', 'password_confirmation' => '87654321',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('87654321', $sara->fresh()->password));

        Password::defaults(fn () => null);
    }

    public function test_an_existing_account_cannot_be_added_as_staff(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'X', 'email' => 'taken@example.com', 'password' => 'secret-pass-1', 'permissions' => [],
        ])->assertSessionHasErrors('email');

        $this->assertFalse($this->store->members()->whereKey($other->id)->exists());
    }

    public function test_the_owner_edits_disables_and_resets_a_member(): void
    {
        $member = $this->staff(['catalog']);

        $this->actingAs($this->owner)->put("/store/team/{$member->id}", [
            'name' => 'Renamed', 'email' => 'renamed@example.com',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $member->fresh()->name);
        $this->assertSame('renamed@example.com', $member->fresh()->email);
        // Editing who they are leaves what they may open alone.
        $this->assertSame(['catalog'], $this->pagesOf($member));

        $this->actingAs($this->owner)->post("/store/team/{$member->id}/disable")->assertSessionHasNoErrors();
        $this->assertSame(User::STATUS_DISABLED, $member->fresh()->status);

        $this->actingAs($this->owner)->post("/store/team/{$member->id}/enable");
        $this->assertSame(User::STATUS_ACTIVE, $member->fresh()->status);

        $this->actingAs($this->owner)->put("/store/team/{$member->id}/password", [
            'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('another-pass-2', $member->fresh()->password));
    }

    public function test_the_owner_changes_the_pages_of_any_member_and_it_applies_at_once(): void
    {
        $member = $this->staff(['catalog', 'reports']);
        $this->actingAs($member)->get('/store/studio')->assertRedirect();

        // Sent in any order, with a page added and a page taken away.
        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", [
            'permissions' => ['studio', 'catalog', 'dashboard'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['dashboard', 'catalog', 'studio'], $this->pagesOf($member));

        $change = ActivityLog::query()->where('action', 'team.member_permissions_changed')->sole();
        $this->assertSame(['dashboard', 'studio'], $change->properties['added']);
        $this->assertSame(['reports'], $change->properties['removed']);

        // The member is still signed in; their next request already follows it.
        $this->app->forgetScopedInstances();
        $this->actingAs($member)->get('/store/studio')->assertOk();
        $this->app->forgetScopedInstances();
        $this->actingAs($member)->get('/store/reports')->assertRedirect();

        // Saving the same pages again changes nothing and records nothing.
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", [
            'permissions' => ['dashboard', 'catalog', 'studio'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityLog::query()->where('action', 'team.member_permissions_changed')->count());
    }

    public function test_every_page_can_be_taken_away_and_a_disabled_member_can_still_be_changed(): void
    {
        $member = $this->staff(['catalog', 'studio']);
        $this->actingAs($this->owner)->post("/store/team/{$member->id}/disable")->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", ['permissions' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->pagesOf($member));
    }

    public function test_only_grantable_pages_can_be_given_and_only_by_the_owner(): void
    {
        $member = $this->staff(['catalog']);
        $colleague = $this->staff(['catalog', 'settings'], name: 'Colleague');

        // The Zid connection is not a page to hand out.
        foreach (['integration', 'connection', 'nonsense'] as $page) {
            $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", ['permissions' => ['catalog', $page]])
                ->assertSessionHasErrors('permissions.1');
        }
        $this->actingAs($this->owner)->put("/store/team/{$member->id}/permissions", [])->assertSessionHasErrors('permissions');

        // A staff member cannot widen anyone's access, their own included.
        $this->app->forgetScopedInstances();
        $this->actingAs($colleague)->put("/store/team/{$member->id}/permissions", ['permissions' => ['dashboard']])->assertForbidden();
        $this->app->forgetScopedInstances();
        $this->actingAs($colleague)->put("/store/team/{$colleague->id}/permissions", ['permissions' => StoreSections::all()])->assertForbidden();

        $this->assertSame(['catalog'], $this->pagesOf($member));
        $this->assertSame(['catalog', 'settings'], $this->pagesOf($colleague));
    }

    public function test_the_members_page_carries_their_pages_for_the_owner(): void
    {
        $member = $this->staff(['studio', 'catalog']);

        $this->actingAs($this->owner)->get("/store/team/{$member->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/team/show')
                ->where('pages.granted', ['catalog', 'studio'])
                ->where('pages.groups', StoreSections::GROUPS));
    }

    public function test_team_management_reaches_only_this_stores_staff(): void
    {
        $elsewhere = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $outsider = $this->staff(['catalog'], $elsewhere);

        // Neither another store's staff nor the owner themselves.
        $this->actingAs($this->owner)->post("/store/team/{$outsider->id}/disable")->assertNotFound();
        $this->actingAs($this->owner)->put("/store/team/{$outsider->id}/permissions", ['permissions' => []])->assertNotFound();
        $this->actingAs($this->owner)->put("/store/team/{$this->owner->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->assertSame(['catalog'], $this->pagesOf($outsider, $elsewhere));
        $this->actingAs($this->owner)->delete("/store/team/{$this->owner->id}")->assertForbidden();
        $this->assertSame(User::STATUS_ACTIVE, $outsider->fresh()->status);
    }

    public function test_removing_a_member_hands_their_products_on_and_deletes_the_account(): void
    {
        $leaving = $this->staff(['catalog'], name: 'Leaving');
        $heir = $this->staff([], name: 'Heir');
        $product = $this->product('Dress');
        $this->claim($leaving, [$product->id]);

        $this->actingAs($this->owner)->delete("/store/team/{$leaving->id}", ['move_to' => $heir->id])
            ->assertRedirect(route('store.team.index'));

        $this->assertSame($heir->id, $product->fresh()->assigned_user_id);
        $this->assertNull(User::query()->find($leaving->id));

        // Without an heir the products are nobody's again.
        $this->actingAs($this->owner)->delete("/store/team/{$heir->id}");
        $this->assertNull($product->fresh()->assigned_user_id);
        $this->assertNull($product->fresh()->assigned_at);
    }

    // ---- Pages a staff member may open ----------------------------------

    public function test_staff_open_only_the_pages_they_were_given(): void
    {
        $member = $this->staff(['catalog']);

        $this->actingAs($member)->get('/store/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/catalog')
                ->where('access.owner', false)
                ->where('access.sections', ['catalog'])
                // The filter by member is the owner's.
                ->where('members', []));

        // A closed page sends them home (their first page) with a notice; a closed action is refused.
        $this->actingAs($member)->get('/store/performance')->assertRedirect(route('store.catalog'));
        $this->actingAs($member)->get('/store/team')->assertRedirect(route('store.catalog'));
        $this->actingAs($member)->post('/store/team', ['name' => 'x'])->assertForbidden();
        $this->actingAs($member)->post('/store/performance/reclassify')->assertForbidden();
        $this->actingAs($member)->delete('/store/integrations/zid')->assertForbidden();
    }

    public function test_staff_without_the_dashboard_land_on_their_products(): void
    {
        $member = $this->staff([]);

        $this->actingAs($member)->get('/dashboard')->assertRedirect(route('store.dashboard'));
        $this->actingAs($member)->get('/store')->assertRedirect(route('store.my-products.index'));
        $this->actingAs($member)->get('/store/my-products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/my-products')->where('asOwner', false));
    }

    public function test_the_zid_connection_is_closed_to_every_staff_member(): void
    {
        // It is not a page the owner can grant at all.
        $this->assertNotContains('connection', StoreSections::all());
        $this->actingAs($this->owner)->post('/store/team', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'secret-pass-1', 'permissions' => ['connection'],
        ])->assertSessionHasErrors('permissions.0');

        // Even a member holding every page, or an old "connection" grant.
        $member = $this->staff([...StoreSections::all(), 'connection']);

        $this->actingAs($member)->get('/store/settings/integration')
            ->assertRedirect(route('store.dashboard'));
        $this->actingAs($member)->get('/store/settings/integration/revenue-audit')->assertRedirect(route('store.dashboard'));
        $this->actingAs($member)->post('/store/integrations/zid/sync')->assertForbidden();
        $this->actingAs($member)->post('/store/integrations/zid/check')->assertForbidden();
        $this->actingAs($member)->post('/store/settings/integration/import/products')->assertForbidden();
        $this->actingAs($member)->delete('/store/integrations/zid')->assertForbidden();
        // The settings shortcut leads to the tab they can open.
        $this->actingAs($member)->get('/store/settings')->assertRedirect(route('store.settings.general'));
        $this->actingAs($member)->get('/store/settings/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('access.sections', StoreSections::all()));

        // Connection alerts reach the owner only.
        $this->assertSame([$this->owner->id], $this->store->recipients('connection')->modelKeys());

        $this->actingAs($this->owner)->get('/store/settings/integration')->assertOk();
    }

    public function test_the_dashboard_sends_staff_only_the_cards_of_their_pages(): void
    {
        $this->product('Dress');
        $member = $this->staff(['dashboard']);

        $this->actingAs($member)->get('/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/dashboard')
                ->where('connection', null)
                ->where('stagnant', null)
                ->where('kpis.at_risk', null)
                ->where('kpis.diversity', null)
                ->where('recentEvaluations', []));

        $this->actingAs($this->owner)->get('/store')
            ->assertInertia(fn (Assert $page) => $page->where('access.owner', true)->has('connection.status')->has('kpis.diversity'));
    }

    public function test_every_store_route_is_mapped_to_a_page_or_kept_for_the_owner(): void
    {
        $ownerOnly = [
            'store.catalog.clear-demo',
            // The Zid connection and everything that rewrites the catalog from outside.
            'store.settings.integration', 'store.settings.integration.frequency', 'store.settings.import', 'store.settings.template',
            'store.settings.revenue-audit', 'store.settings.revenue-audit.run',
            'store.integrations.zid.redirect', 'store.integrations.zid.callback', 'store.integrations.zid.disconnect',
            'store.integrations.zid.sync', 'store.integrations.zid.sync.resume', 'store.integrations.zid.check',
            // The team-wide two-factor rule.
            'store.team.security',
        ];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'store.')) {
                continue;
            }

            $sections = StoreSections::forRoute($name);

            if (in_array($name, $ownerOnly, true)) {
                $this->assertSame(StoreSections::OWNER_ONLY, $sections, "{$name} must stay owner-only");

                continue;
            }

            $this->assertNotSame(StoreSections::OWNER_ONLY, $sections, "{$name} is not mapped to a page in StoreSections");
            $this->assertSame([], array_diff($sections, [...StoreSections::all(), '*']), "{$name} maps to an unknown page");
        }

        $this->assertSame(['team'], StoreSections::forRoute('store.team.index'));
        $this->assertSame(['team'], StoreSections::forRoute('store.team.permissions'));
        $this->assertSame(['team_report'], StoreSections::forRoute('store.team.report'));
        $this->assertSame(['team_report'], StoreSections::forRoute('store.team.products'));
        $this->assertSame(StoreSections::OWNER_ONLY, StoreSections::forRoute('store.something.new'));
    }

    // ---- Products each member holds -------------------------------------

    public function test_a_product_held_by_one_member_cannot_be_taken_by_another(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $noor = $this->staff([], name: 'Noor');
        $dress = $this->product('Dress');
        $abaya = $this->product('Abaya');

        $this->actingAs($sara)->post('/store/my-products', ['ids' => [$dress->id]])->assertSessionHasNoErrors();
        $this->assertSame($sara->id, $dress->fresh()->assigned_user_id);
        $this->assertNotNull($dress->fresh()->assigned_at);

        // Noor is told who has it, and it stays with Sara.
        $this->actingAs($noor)->post('/store/my-products', ['ids' => [$dress->id]])->assertSessionHasErrors('ids');
        $this->assertStringContainsString('Sara', session('errors')->first('ids'));
        $this->assertSame($sara->id, $dress->fresh()->assigned_user_id);

        // In a mixed batch she gets the free one only.
        $this->actingAs($noor)->post('/store/my-products', ['ids' => [$dress->id, $abaya->id]])->assertSessionHasNoErrors();
        $this->assertSame($sara->id, $dress->fresh()->assigned_user_id);
        $this->assertSame($noor->id, $abaya->fresh()->assigned_user_id);

        // She cannot release what is not hers; once Sara lets go, she can take it.
        $this->actingAs($noor)->delete('/store/my-products', ['ids' => [$dress->id]]);
        $this->assertSame($sara->id, $dress->fresh()->assigned_user_id);
        $this->actingAs($sara)->delete('/store/my-products', ['ids' => [$dress->id]]);
        $this->assertNull($dress->fresh()->assigned_user_id);
        $this->actingAs($noor)->post('/store/my-products', ['ids' => [$dress->id]])->assertSessionHasNoErrors();
        $this->assertSame($noor->id, $dress->fresh()->assigned_user_id);
    }

    public function test_products_of_another_store_cannot_be_taken(): void
    {
        $elsewhere = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $foreign = $elsewhere->products()->create(['name' => 'Theirs', 'external_id' => 't']);
        $member = $this->staff([]);

        $this->actingAs($member)->post('/store/my-products', ['ids' => [$foreign->id]])->assertSessionHasErrors('ids');
        $this->assertNull($foreign->fresh()->assigned_user_id);
    }

    public function test_the_owner_moves_a_product_from_one_member_to_another(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $noor = $this->staff([], name: 'Noor');
        $dress = $this->product('Dress');
        $this->claim($sara, [$dress->id]);

        $this->actingAs($this->owner)->post("/store/team/{$noor->id}/products", ['ids' => [$dress->id]])->assertSessionHasNoErrors();
        $this->assertSame($noor->id, $dress->fresh()->assigned_user_id);

        $this->actingAs($this->owner)->delete("/store/team/{$noor->id}/products", ['ids' => [$dress->id]]);
        $this->assertNull($dress->fresh()->assigned_user_id);

        // Staff have no way into another member's list.
        $this->actingAs($sara)->post("/store/team/{$noor->id}/products", ['ids' => [$dress->id]])->assertForbidden();
    }

    public function test_a_sync_style_update_never_touches_who_holds_the_product(): void
    {
        $member = $this->staff([]);
        $dress = $this->product('Dress');
        $this->claim($member, [$dress->id]);

        // The Zid sync and the file import both fill() and save().
        $dress->fresh()->fill(['name' => 'Dress v2', 'price' => 120, 'assigned_user_id' => null, 'assigned_at' => null])->save();

        $this->assertSame('Dress v2', $dress->fresh()->name);
        $this->assertSame($member->id, $dress->fresh()->assigned_user_id);
    }

    public function test_holding_a_product_does_not_open_its_sales_page(): void
    {
        $limited = $this->staff([], name: 'Limited');
        $browser = $this->staff(['catalog'], name: 'Browser');
        $mine = $this->product('Mine');
        $other = $this->product('Other');
        $this->claim($limited, [$mine->id]);

        // The product page is its sales and performance: the manager's to
        // see, and a staff member's only through a page the manager gave.
        $this->actingAs($limited)->get("/store/products/{$mine->id}")->assertForbidden();
        $this->actingAs($limited)->get("/store/products/{$other->id}")->assertForbidden();
        $this->actingAs($browser)->get("/store/products/{$other->id}")->assertOk();
        $this->actingAs($this->owner)->get("/store/products/{$mine->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assignee.name', 'Limited'));
    }

    public function test_a_staff_member_sees_their_products_without_sales_or_performance(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $dress = $this->product('Dress', ['performance' => Product::WINNER, 'performance_score' => 90]);
        $free = $this->product('Free abaya', ['performance' => Product::STAGNANT]);
        $this->sale($dress, 0, 10, price: 300);
        $this->sale($free, 0, 4);
        $this->claim($sara, [$dress->id]);
        $this->store->update(['settings' => ['show_revenue' => true]]);

        $noSales = fn (Assert $page) => $page
            ->where('asOwner', false)
            ->where('stats', null)
            ->where('availableMonths', [])
            ->has('products.data', 1)
            ->missing('products.data.0.units')
            ->missing('products.data.0.orders')
            ->missing('products.data.0.revenue')
            ->missing('products.data.0.history')
            ->missing('products.data.0.performance');

        // Her own products: the list, and nothing about how it sells.
        $this->actingAs($sara)->get('/store/my-products')
            ->assertInertia(fn (Assert $page) => $noSales($page)->where('products.data.0.name', 'Dress')->where('counts.mine', 1));
        // The products on offer: the same. Asking to rank by sales is ignored.
        $this->get('/store/my-products?tab=add&sort=units')
            ->assertInertia(fn (Assert $page) => $noSales($page)->where('products.data.0.name', 'Free abaya')->where('sort', 'newest'));

        // The manager sees it all for that member.
        $this->actingAs($this->owner)->get("/store/team/{$sara->id}?sort=units")
            ->assertInertia(fn (Assert $page) => $page
                ->where('asOwner', true)
                ->where('sort', 'units')
                ->where('stats.units', 10)
                ->where('products.data.0.units', 10)
                ->where('products.data.0.revenue', 3000)
                ->where('products.data.0.performance', Product::WINNER)
                ->has('products.data.0.history', 12));
    }

    public function test_a_product_without_a_launch_date_goes_after_the_dated_ones(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $undated = $this->product('Undated');
        $old = $this->product('Old dress', ['launched_at' => now()->subYear()]);
        $new = $this->product('New arrival', ['launched_at' => now()->subWeek()]);

        $this->actingAs($sara)->get('/store/my-products?tab=add')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.id', $new->id)
                ->where('products.data.1.id', $old->id)
                ->where('products.data.2.id', $undated->id));
    }

    public function test_products_created_the_same_day_keep_the_stores_order(): void
    {
        $sara = $this->staff([], name: 'Sara');
        // Read from Zid newest first, so the newest row has the lowest id.
        $evening = $this->product('Evening', ['launched_at' => '2026-09-16', 'listed_at' => '2026-09-16 21:40:00']);
        $noon = $this->product('Noon', ['launched_at' => '2026-09-16', 'listed_at' => '2026-09-16 12:05:00']);
        $morning = $this->product('Morning', ['launched_at' => '2026-09-16', 'listed_at' => '2026-09-16 08:30:00']);
        $dayBefore = $this->product('Day before', ['launched_at' => '2026-09-15', 'listed_at' => '2026-09-15 23:59:00']);

        $this->actingAs($sara)->get('/store/my-products?tab=add')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.id', $evening->id)
                ->where('products.data.1.id', $noon->id)
                ->where('products.data.2.id', $morning->id)
                ->where('products.data.3.id', $dayBefore->id));

        // The catalog's "newest" follows the same order.
        $this->actingAs($this->owner)->get('/store/catalog?sort=newest')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.id', $evening->id)
                ->where('products.data.2.id', $morning->id));
    }

    public function test_the_zid_product_keeps_the_time_it_was_created(): void
    {
        $row = app(\App\Services\Zid\ZidMapper::class)->product(['id' => 'p1', 'name' => 'Dress', 'created_at' => '2026-09-16T18:40:12Z']);

        $this->assertSame('2026-09-16', $row['launched_at']);
        $this->assertSame(\Illuminate\Support\Carbon::parse('2026-09-16T18:40:12Z')->setTimezone(config('app.timezone'))->toDateTimeString(), $row['listed_at']);
    }

    public function test_the_list_pages_by_twenty_and_an_emptied_last_page_shows_the_page_before(): void
    {
        $sara = $this->staff([], name: 'Sara');
        foreach (range(1, TeamReport::PER_PAGE + 2) as $i) {
            $this->product("Dress {$i}", ['launched_at' => now()->subDays($i)]);
        }

        $this->actingAs($sara)->get('/store/my-products?tab=add&page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.current_page', 2)
                ->where('products.last_page', 2)
                ->has('products.data', 2));

        // She adds the two products on page 2; page 2 no longer exists.
        $this->claim($sara, Product::query()->orderBy('launched_at')->limit(2)->pluck('id')->all());

        $this->actingAs($sara)->get('/store/my-products?tab=add&page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.current_page', 1)
                ->has('products.data', TeamReport::PER_PAGE));
    }

    public function test_a_newly_added_product_comes_first(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $old = $this->product('Old dress', ['launched_at' => now()->subMonths(8)]);
        $best = $this->product('Best seller', ['launched_at' => now()->subMonths(5)]);
        $new = $this->product('New arrival', ['launched_at' => now()->subDay()]);
        $this->sale($best, 0, 50);

        // On offer: the product launched last leads, whatever sells most.
        $this->actingAs($sara)->get('/store/my-products?tab=add')
            ->assertInertia(fn (Assert $page) => $page->where('sort', 'newest')
                ->where('products.data.0.id', $new->id)
                ->where('products.data.1.id', $best->id)
                ->where('products.data.2.id', $old->id));

        // Her own: the one she added last leads, even though it is the oldest product.
        $this->claim($sara, [$new->id]);
        $this->travel(5)->minutes();
        $this->claim($sara, [$best->id]);
        $this->travel(5)->minutes();
        $this->claim($sara, [$old->id]);

        $this->actingAs($sara)->get('/store/my-products')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.id', $old->id)
                ->where('products.data.1.id', $best->id)
                ->where('products.data.2.id', $new->id));

        // The manager gets the same order, and can rank by units instead.
        $this->actingAs($this->owner)->get("/store/team/{$sara->id}")
            ->assertInertia(fn (Assert $page) => $page->where('sort', 'newest')->where('products.data.0.id', $old->id));
        $this->get("/store/team/{$sara->id}?sort=units")
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.id', $best->id));
    }

    // ---- The owner's comparison -----------------------------------------

    public function test_the_report_compares_members_by_what_their_products_sold(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $noor = $this->staff([], name: 'Noor');
        $this->staff([], name: 'Idle');
        $dress = $this->product('Dress', ['performance' => Product::WINNER]);
        $abaya = $this->product('Abaya', ['performance' => Product::STAGNANT]);
        $scarf = $this->product('Scarf');
        $loose = $this->product('Loose');

        // Sold before anyone took them: it all counts for whoever holds them now.
        $this->sale($dress, 0, 10);
        $this->sale($dress, 1, 20);
        $this->sale($abaya, 1, 6);
        $this->sale($scarf, 0, 4);
        $this->sale($loose, 0, 10);
        $this->sale($dress, 5, 100);  // outside a three-month window

        $this->claim($sara, [$dress->id, $abaya->id]);
        $this->claim($noor, [$scarf->id]);

        $report = app(TeamReport::class)->comparison($this->store, 3);
        $rows = collect($report['members'])->keyBy('name');

        $this->assertSame(['Sara', 'Noor', 'Idle'], array_column($report['members'], 'name'));
        $this->assertSame(2, $rows['Sara']['products']);
        $this->assertSame(2, $rows['Sara']['selling']);
        $this->assertSame(36, $rows['Sara']['units']);
        $this->assertSame(18.0, $rows['Sara']['avg_per_product']);
        $this->assertSame(12.0, $rows['Sara']['avg_per_month']);
        $this->assertSame(1, $rows['Sara']['winners']);
        $this->assertSame(1, $rows['Sara']['stagnant']);
        $this->assertSame('Dress', $rows['Sara']['best']['name']);
        // Month by month, oldest first: two months ago, last month, this month.
        $this->assertSame([0, 26, 10], array_column($rows['Sara']['monthly'], 'units'));
        $this->assertSame(4, $rows['Noor']['units']);
        $this->assertSame(0, $rows['Idle']['units']);
        $this->assertNull($rows['Idle']['best']);

        $this->assertSame(1, $report['unassigned']['products']);
        $this->assertSame(10, $report['unassigned']['units']);
        $this->assertSame(['products' => 4, 'assigned' => 3, 'units' => 50], array_intersect_key($report['totals'], ['products' => 0, 'assigned' => 0, 'units' => 0]));
        $this->assertSame(72.0, $rows['Sara']['share']);

        // Moving a product moves its sales with it.
        app(ProductAssignments::class)->assign($this->store, $noor, [$dress->id]);
        $moved = collect(app(TeamReport::class)->comparison($this->store, 3)['members'])->keyBy('name');
        $this->assertSame(34, $moved['Noor']['units']);
        $this->assertSame(6, $moved['Sara']['units']);
    }

    public function test_the_team_page_hides_revenue_until_the_admin_shows_it(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $dress = $this->product('Dress');
        $this->sale($dress, 0, 10, price: 300);
        $this->claim($sara, [$dress->id]);

        $this->actingAs($this->owner)->get('/store/team/report?months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/team/report')
                ->where('report.members.0.name', 'Sara')
                ->where('report.members.0.units', 10)
                ->where('report.members.0.revenue', null)
                ->where('report.members.0.revenue_share', null)
                ->where('report.members.0.monthly.2.revenue', null)
                ->where('report.totals.revenue', null));

        $this->actingAs($this->owner)->get("/store/team/{$sara->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/team/show')
                ->where('asOwner', true)
                ->where('stats.units', 10)
                ->where('products.data.0.name', 'Dress')
                ->where('products.data.0.units', 10)
                ->where('products.data.0.revenue', null)
                ->where('products.data.0.history.11.units', 10));

    }

    public function test_the_team_page_shows_revenue_once_the_admin_allows_it(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $dress = $this->product('Dress');
        $this->sale($dress, 0, 10, price: 300);
        $this->claim($sara, [$dress->id]);
        $this->store->update(['settings' => ['show_revenue' => true]]);

        $this->actingAs($this->owner)->get('/store/team/report?months=3')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.members.0.revenue', 3000)
                ->where('report.members.0.revenue_share', 100)
                ->where('report.members.0.monthly.2.revenue', 3000)
                ->where('report.totals.revenue', 3000));
    }

    public function test_the_owner_exports_the_comparison_for_the_members_left_by_the_filters(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $noor = $this->staff(['reports'], name: 'Noor');
        $dress = $this->product('Dress');
        $this->sale($dress, 0, 10, price: 300);
        $this->claim($sara, [$dress->id]);

        $this->actingAs($this->owner)->get("/store/team/export?months=3&sort=products&members={$sara->id},999")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/team/print')
                ->where('teamSize', 2)
                ->where('sort', 'products')
                ->has('report.members', 1)
                ->where('report.members.0.name', 'Sara')
                ->where('report.members.0.units', 10)
                // Revenue stays hidden on paper too.
                ->where('report.members.0.revenue', null)
                ->has('report.months', 3));

        $this->assertTrue(ActivityLog::query()->where('action', 'team.report_exported')->where('store_id', $this->store->id)->exists());

        // The team report needs its own page, whatever else a member holds.
        $this->actingAs($noor)->get('/store/team/export')->assertRedirect(route('store.reports.index'));
    }

    public function test_a_member_sees_free_products_and_learns_who_holds_the_rest_only_by_searching(): void
    {
        $sara = $this->staff([], name: 'Sara');
        $noor = $this->staff([], name: 'Noor');
        $taken = $this->product('Silk dress');
        $this->product('Cotton dress');
        $this->claim($sara, [$taken->id]);

        $this->actingAs($noor)->get('/store/my-products?tab=add')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.free', 1)
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Cotton dress'));

        $this->actingAs($noor)->get('/store/my-products?tab=add&search=Silk')
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.assignee.name', 'Sara'));

        // The owner follows products per member, from the team page.
        $this->actingAs($this->owner)->get('/store/my-products')->assertRedirect(route('store.team.index'));
    }

    public function test_the_catalog_shows_who_holds_a_product_and_filters_by_member_for_the_owner(): void
    {
        $sara = $this->staff(['catalog'], name: 'Sara');
        $dress = $this->product('Dress');
        $this->product('Abaya');
        $this->claim($sara, [$dress->id]);

        $this->actingAs($this->owner)->get("/store/catalog?member={$sara->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('members.0.name', 'Sara')
                ->has('products.data', 1)
                ->where('products.data.0.assignee.name', 'Sara'));

        $this->actingAs($this->owner)->get('/store/catalog?member=none')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Abaya'));

        // The filter is ignored for staff.
        $this->actingAs($sara)->get('/store/catalog?member=none')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
    }

    // ---- Around the edges ------------------------------------------------

    public function test_staff_see_only_their_own_part_of_the_activity_log(): void
    {
        $member = $this->staff(['activity']);
        ActivityLog::query()->create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'action' => 'settings.updated', 'created_at' => now()]);
        ActivityLog::query()->create(['store_id' => $this->store->id, 'user_id' => $member->id, 'action' => 'product.claimed', 'created_at' => now()]);

        $this->actingAs($member)->get('/store/activity')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'product.claimed'));
        $this->actingAs($this->owner)->get('/store/activity')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    }

    public function test_alerts_reach_the_owner_and_the_staff_who_hold_that_page(): void
    {
        $market = $this->staff(['market'], name: 'Market');
        $this->staff(['catalog'], name: 'Catalog');

        $this->assertEqualsCanonicalizing([$this->owner->id, $market->id], $this->store->recipients('market')->modelKeys());
        $this->assertEqualsCanonicalizing([$this->owner->id], $this->store->recipients('balance')->modelKeys());
    }

    public function test_deleting_a_store_removes_its_staff_accounts(): void
    {
        $member = $this->staff(['catalog']);

        app(DeleteStore::class)->handle($this->store);

        $this->assertNull(User::query()->find($member->id));
        $this->assertNotNull(User::query()->find($this->owner->id));
    }
}
