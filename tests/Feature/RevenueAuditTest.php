<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\AuditZidRevenue;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Zid\RevenueAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RevenueAuditTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Audit test']);
        $this->store->update([
            'platform' => 'zid', 'platform_store_id' => '41960', 'connection_status' => 'connected',
            'zid_authorization' => 'app-token', 'zid_access_token' => 'manager-token',
        ]);

        $real1 = $this->store->products()->create(['name' => 'Abaya', 'external_id' => 'real-1']);
        // Zid writes product UUIDs with dashes, and an option's parent_id without them.
        $this->store->products()->create(['name' => 'Scarf', 'external_id' => 'B2C3D4E5-0000-4000-8000-000000000002', 'sku' => 'SKU-2']);
        $demo = $this->store->products()->create(['name' => 'Demo item']);
        $period = Carbon::parse('2026-10-01')->startOfDay();
        ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $real1->id, 'period' => $period, 'orders' => 2, 'units' => 3, 'revenue' => 60]);
        ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $demo->id, 'period' => $period, 'orders' => 5, 'units' => 5, 'revenue' => 500]);

        // What the last import stored: order 101 has since been canceled in Zid.
        DB::table('zid_orders')->insert([
            ['store_id' => $this->store->id, 'external_id' => '100', 'month' => '2026-10-01', 'returned' => false, 'lines' => json_encode([['external_id' => 'real-1', 'units' => 2, 'revenue' => 40]])],
            ['store_id' => $this->store->id, 'external_id' => '101', 'month' => '2026-10-01', 'returned' => false, 'lines' => json_encode([['external_id' => 'real-1', 'units' => 1, 'revenue' => 20]])],
        ]);

        $this->actingAs($this->owner);
    }

    /** @return list<array<string, mixed>> */
    private function zidOrders(): array
    {
        $order = fn (int $id, string $at, string $status, float $total, array $lines, array $extra = []) => [
            'id' => $id, 'created_at' => $at, 'order_status' => ['code' => $status], 'order_total' => $total,
            'currency_code' => 'SAR', 'products' => $lines, ...$extra,
        ];

        return [
            // Zid times are UTC: 22:00 on Sep 30 is 01:00 on Oct 1 in Riyadh, 20:00 is still September.
            $order(107, '2026-09-30 20:00:00', 'delivered', 9, [['id' => 'real-1', 'quantity' => 1, 'total' => 9]]),
            $order(104, '2026-09-30 22:00:00', 'delivered', 10, [['id' => 'real-1', 'quantity' => 1, 'total' => 10]], [
                'customer' => ['name' => 'Huda', 'mobile' => '0555000000'],
            ]),
            $order(100, '2026-10-05 10:00:00', 'delivered', 55, [['id' => 'real-1', 'quantity' => 2, 'total' => 40]], [
                'customer' => ['name' => 'Sara', 'mobile' => '0555000000'], 'shipping' => ['method' => ['cost' => 15]],
            ]),
            $order(101, '2026-10-06 10:00:00', 'cancelled', 23, [['id' => 'real-1', 'quantity' => 1, 'total' => 20]]),
            $order(102, '2026-10-07 10:00:00', 'new', 30, [['id' => 'var-9', 'parent_id' => 'b2c3d4e5000040008000000000000002', 'sku' => 'X', 'name' => 'Scarf / Red', 'quantity' => 1, 'total' => 30]]),
            $order(103, '2026-10-08 10:00:00', 'returned', 20, [['id' => 'real-1', 'quantity' => 1, 'total' => 20]]),
            $order(105, '2026-10-09 10:00:00', 'delivered', 15, [['id' => 'b2c3d4e5-0000-4000-8000-000000000002', 'quantity' => 1, 'price' => 15]]),
            $order(106, '2026-10-10 10:00:00', 'delivered', 12, [['id' => 'gone-1', 'name' => 'Deleted product', 'quantity' => 1, 'total' => 12]]),
        ];
    }

    private function fakeZid(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $orders = $this->zidOrders();
            if (($query['per_page'] ?? null) === '1') {
                return Http::response(['orders' => [$orders[2]], 'total_order_count' => 7, 'grand_total' => 165]);
            }

            return Http::response(['orders' => $orders, 'total_order_count' => count($orders)]);
        });
    }

    public function test_the_audit_explains_each_part_of_the_gap(): void
    {
        $this->fakeZid();

        $report = app(RevenueAudit::class)->run($this->store, '2026-10', 142.0);

        $this->assertSame(560.0, $report['ours']['sales']['revenue']);
        $this->assertSame(500.0, $report['ours']['sales']['other_revenue']);
        $this->assertSame(7, $report['zid']['month_orders']);
        $this->assertSame(6, $report['zid']['counted_orders']);
        $this->assertTrue($report['zid']['read']['complete']);
        $this->assertSame([
            'order_total_all' => 165.0, 'order_total_counted' => 142.0, 'order_total_not_returned' => 122.0,
            'lines_counted' => 127.0, 'lines_kept' => 107.0, 'our_method' => 95.0,
        ], $report['zid']['totals']);
        // The option line counts for its product; only the deleted product is lost.
        $this->assertSame(['lines' => 1, 'revenue' => 30.0], $report['zid']['matched_by_parent']);
        $this->assertSame(12.0, $report['zid']['unmatched']['revenue']);
        $this->assertSame('gone-1', $report['zid']['unmatched']['samples'][0]['id']);
        $this->assertSame(15.0, $report['zid']['order_fields']['shipping.method.cost']);

        $reconcile = $report['zid']['reconciliation'];
        $this->assertSame(5, $reconcile['missing_from_ours']['count']);
        $this->assertSame(1, $reconcile['extra_in_ours']['cancelled_in_zid']);

        $codes = array_column($report['findings'], 'code');
        $this->assertNotContains('variant_lines', $codes);
        foreach (['non_zid_rows', 'stale_import', 'unmatched_lines', 'order_level_amounts', 'returns_excluded', 'cancelled_excluded', 'missing_orders', 'extra_orders'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame('non_zid_rows', $report['findings'][0]['code']);
        $this->assertSame('zid_order_total_counted', $report['candidates']['closest_to_expected']);
        $this->assertContains('zid_list_utc_grand_total', array_column($report['candidates']['rows'], 'key'));

        // Customer details never reach the report.
        $this->assertSame('[hidden]', $report['zid']['sample_order']['customer']);
        $this->assertStringNotContainsString('0555000000', json_encode($report));
    }

    public function test_the_page_queues_an_audit_and_shows_its_report(): void
    {
        Bus::fake();
        $this->withoutVite();

        $this->post('/store/settings/integration/revenue-audit', ['month' => '2026-10', 'expected' => '120.5'])
            ->assertRedirect('/store/settings/integration/revenue-audit');

        $run = SyncRun::query()->where('type', RevenueAudit::TYPE)->sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame(['month' => '2026-10', 'expected' => 120.5], array_intersect_key($run->stats, ['month' => 1, 'expected' => 1]));
        Bus::assertDispatched(AuditZidRevenue::class, fn (AuditZidRevenue $job) => $job->runId === $run->id);

        // A second request while one runs does not queue another.
        $this->post('/store/settings/integration/revenue-audit', ['month' => '2026-09'])->assertRedirect();
        $this->assertSame(1, SyncRun::query()->where('type', RevenueAudit::TYPE)->count());

        $this->fakeZid();
        app()->call([new AuditZidRevenue($run->id), 'handle']);
        $this->assertSame('completed', $run->fresh()->status);

        $this->get('/store/settings/integration/revenue-audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/settings/revenue-audit')
                ->where('connected', true)
                ->where('audit.status', 'completed')
                ->where('audit.report.zid.totals.our_method', 95)
                ->has('history', 1));

        // Audits stay out of the sync history on the integration page.
        $this->get('/store/settings/integration')
            ->assertInertia(fn (Assert $page) => $page->has('runs', 0));
    }

    public function test_months_outside_the_window_and_disconnected_stores_are_refused(): void
    {
        Bus::fake();
        $this->store->update(['connection_status' => 'disconnected']);

        $this->post('/store/settings/integration/revenue-audit', ['month' => '2026-10'])->assertRedirect();
        $this->post('/store/settings/integration/revenue-audit', ['month' => '2026-11'])->assertSessionHasErrors('month');
        $this->post('/store/settings/integration/revenue-audit', ['month' => '2025-10'])->assertSessionHasErrors('month');

        $this->assertSame(0, SyncRun::query()->where('type', RevenueAudit::TYPE)->count());
        Bus::assertNotDispatched(AuditZidRevenue::class);
    }

    public function test_a_failed_read_is_recorded_on_the_run(): void
    {
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);
        $run = SyncRun::query()->create(['store_id' => $this->store->id, 'type' => RevenueAudit::TYPE, 'status' => 'queued', 'stats' => ['month' => '2026-10', 'expected' => null]]);

        app()->call([new AuditZidRevenue($run->id), 'handle']);

        // Summary and page reads fail but the report still says why.
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->stats['report']['zid']['read']['complete']);
        $this->assertSame(403, $run->stats['report']['zid']['read']['stopped']['http']);
        // A partial read only reports what does not depend on Zid's numbers.
        $this->assertEqualsCanonicalizing(['non_zid_rows', 'incomplete_read'], array_column($run->stats['report']['findings'], 'code'));
    }
}
