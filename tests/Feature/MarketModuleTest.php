<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\CheckBalance;
use App\Models\ActivityLog;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\CustomerReview;
use App\Models\Product;
use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Catalog\BalanceService;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\PublicWebClient;
use App\Services\Market\SentimentAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Market trends, competitors, catalog balance, calendar, reports,
 * notifications and the activity log (modules 2, 5 and 6).
 */
class MarketModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Store}
     */
    private function ownerWithStore(string $name = 'Store'): array
    {
        $owner = User::factory()->create();
        $store = app(CreateStore::class)->handle($owner, ['name' => $name]);

        return [$owner, $store];
    }

    public function test_module_pages_render(): void
    {
        [$owner] = $this->ownerWithStore();

        foreach ([
            '/store/market' => 'store/market',
            '/store/competitors' => 'store/competitors',
            '/store/balance' => 'store/balance',
            '/store/calendar' => 'store/calendar',
            '/store/reports' => 'store/reports/index',
            '/store/notifications' => 'store/notifications',
            '/store/activity' => 'store/activity',
        ] as $url => $component) {
            $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_competitors_can_be_managed_and_are_scoped_to_the_store(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        [, $other] = $this->ownerWithStore('Other');

        $this->actingAs($owner)->post('/store/competitors', [
            'name' => 'Rival', 'platform' => 'salla', 'url' => 'https://rival.salla.sa', 'is_active' => true,
        ])->assertRedirect();

        $competitor = $store->competitors()->firstOrFail();
        $this->assertSame('Rival', $competitor->name);

        $this->actingAs($owner)->put("/store/competitors/{$competitor->id}", [
            'name' => 'Rival 2', 'platform' => 'instagram', 'handle' => '@rival', 'is_active' => false,
        ])->assertRedirect();

        $competitor->refresh();
        $this->assertSame('rival', $competitor->handle);
        $this->assertFalse($competitor->is_active);

        // Social accounts need a handle; stores need a URL.
        $this->actingAs($owner)->post('/store/competitors', ['name' => 'X', 'platform' => 'tiktok'])->assertSessionHasErrors('handle');
        $this->actingAs($owner)->post('/store/competitors', ['name' => 'Y', 'platform' => 'zid'])->assertSessionHasErrors('url');

        $foreign = Competitor::query()->create(['store_id' => $other->id, 'name' => 'Theirs', 'platform' => 'zid', 'url' => 'https://t.zid.store']);
        $this->actingAs($owner)->delete("/store/competitors/{$foreign->id}")->assertNotFound();

        $this->actingAs($owner)->delete("/store/competitors/{$competitor->id}")->assertRedirect();
        $this->assertDatabaseMissing('competitors', ['id' => $competitor->id]);
        $this->assertTrue(ActivityLog::query()->where('action', 'competitor.deleted')->exists());
    }

    public function test_shopify_scan_tracks_new_products_and_price_changes(): void
    {
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
        config(['mojo.ai.provider' => 'fake']);
        [$owner, $store] = $this->ownerWithStore();
        $competitor = Competitor::query()->create(['store_id' => $store->id, 'name' => 'Shop', 'platform' => 'shopify', 'url' => 'https://shop.example.com']);

        $product = fn (string $price) => ['products' => [[
            'id' => 7, 'title' => 'Black Embroidered Crepe Abaya', 'handle' => 'black', 'product_type' => 'Abayas',
            'published_at' => now()->subDays(2)->toIso8601String(), 'variants' => [['price' => $price]], 'images' => [],
        ]]];

        Http::fake([
            'shop.example.com/robots.txt' => Http::response('User-agent: *'),
            'https://shop.example.com' => Http::response('<main><a href="/collections/best">Best sellers</a></main>'),
            'shop.example.com/products.json*' => Http::sequence()->push($product('400.00'))->push($product('350.00')),
            'shop.example.com/collections/best/products.json*' => Http::response(['products' => []]),
            'shop.example.com/collections/best' => Http::response('<main><a href="/products/black">Black abaya</a></main>'),
        ]);

        app(CompetitorMonitor::class)->scan($competitor);
        app(CompetitorMonitor::class)->scan($competitor);

        $tracked = CompetitorProduct::query()->where('competitor_id', $competitor->id)->sole();
        $this->assertSame('350.00', $tracked->price);
        $this->assertSame('400.00', $tracked->previous_price);
        $this->assertTrue($tracked->is_best_seller);
        // "Embroidered" must not be read as the colour red.
        $this->assertSame(['black'], $tracked->features['colors']);
        $this->assertSame('embroidery', $tracked->features['pattern']);
        $this->assertNotNull($competitor->fresh()->last_checked_at);
    }

    public function test_platforms_without_a_public_feed_are_reported_not_failed(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $competitor = Competitor::query()->create(['store_id' => $store->id, 'name' => 'Insta', 'platform' => 'instagram', 'handle' => 'x']);

        app(CompetitorMonitor::class)->scan($competitor);

        $this->assertNull($competitor->fresh()->last_error);
        $this->assertNull($competitor->fresh()->last_checked_at);
    }

    public function test_store_events_are_editable_but_platform_events_are_not(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $global = SeasonalEvent::query()->create([
            'name_ar' => 'رمضان', 'name_en' => 'Ramadan', 'season_key' => 'ramadan',
            'starts_on' => now()->addMonths(3), 'ends_on' => now()->addMonths(4), 'prep_weeks' => 8,
        ]);

        $this->actingAs($owner)->post('/store/calendar', [
            'name_ar' => 'تخفيضات المتجر', 'name_en' => 'Store sale', 'season_key' => null,
            'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonth()->addDays(3)->toDateString(),
            'prep_weeks' => 3, 'palette' => ['gold', 'black'],
        ])->assertRedirect();

        $event = SeasonalEvent::query()->where('store_id', $store->id)->sole();
        $this->assertSame(['gold', 'black'], $event->palette);

        $this->actingAs($owner)->get('/store/calendar')->assertInertia(fn (Assert $page) => $page
            ->has('events', 2)
            ->where('events.0.is_global', false)
            ->where('events.1.launch_on', $global->starts_on->subWeeks(8)->toDateString()));

        $this->actingAs($owner)->delete("/store/calendar/{$global->id}")->assertForbidden();
        $this->actingAs($owner)->delete("/store/calendar/{$event->id}")->assertRedirect();
        $this->assertDatabaseMissing('seasonal_events', ['id' => $event->id]);
    }

    public function test_notifications_are_listed_and_marked_read(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $owner->notify(new PlatformNotification('trend', 'عنوان', 'Title', storeId: $store->id, url: '/store/market'));
        $owner->notify(new PlatformNotification('balance', 'عنوان ٢', 'Title 2', storeId: $store->id));

        $this->actingAs($owner)->get('/store/notifications?filter=unread')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 2)->where('counts.unread', 2));

        $id = $owner->notifications()->where('data->kind', 'trend')->value('id');
        $this->actingAs($owner)->post("/store/notifications/{$id}/read", ['open' => 1])->assertRedirect('/store/market');

        $this->actingAs($owner)->post('/store/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_reports_export_to_excel_and_print(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        Product::query()->create(['store_id' => $store->id, 'name' => 'Abaya', 'category' => 'عبايات', 'price' => 300, 'features' => ['colors' => ['black']]]);

        foreach (['performance', 'market', 'evaluations', 'balance'] as $type) {
            $response = $this->actingAs($owner)->get("/store/reports/{$type}/export?format=xlsx");
            $response->assertOk();
            $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('PK', $response->streamedContent());
        }

        $this->actingAs($owner)->get('/store/reports/balance/export?format=pdf')
            ->assertInertia(fn (Assert $page) => $page->component('store/reports/print')->has('report.sections'));

        $this->actingAs($owner)->get('/store/reports/unknown/export')->assertNotFound();
        $this->assertSame(5, ActivityLog::query()->where('action', 'report.exported')->count());
    }

    public function test_keyword_sentiment_reads_saudi_dialect(): void
    {
        $analyzer = app(SentimentAnalyzer::class);

        $this->assertSame('positive', $analyzer->keywords('القماش مره حلو والقصة تجنن')['sentiment']);
        $this->assertSame('negative', $analyzer->keywords('ما عجبني القصة')['sentiment']);
        $this->assertSame('negative', $analyzer->keywords('وصلني مكسور للأسف')['sentiment']);
        $this->assertContains('delivery', $analyzer->keywords('التوصيل تأخر')['topics']);
    }

    public function test_reviews_are_analysed_in_demo_mode(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        CustomerReview::query()->create(['store_id' => $store->id, 'text' => 'الخامة رهيبة', 'rating' => 5]);

        $this->actingAs($owner)->post('/store/market/reviews/analyze')->assertRedirect();

        $review = CustomerReview::query()->sole();
        $this->assertSame('positive', $review->sentiment);
        $this->assertNotNull($review->analyzed_at);
    }

    public function test_balance_alerts_notify_once(): void
    {
        Notification::fake();
        [$owner, $store] = $this->ownerWithStore();

        // Ten identical products: a repetitive category.
        foreach (range(1, 10) as $i) {
            Product::query()->create([
                'store_id' => $store->id, 'name' => "Abaya {$i}", 'category' => 'عبايات', 'price' => 300,
                'features' => ['colors' => ['black'], 'pattern' => 'solid', 'style' => 'classic', 'material' => 'crepe'],
            ]);
        }

        $this->assertSame('repetitive', app(BalanceService::class)->analyze($store)['categories'][0]['status']);

        (new CheckBalance($store->id))->handle(app(BalanceService::class));
        (new CheckBalance($store->id))->handle(app(BalanceService::class));

        Notification::assertSentToTimes($owner, PlatformNotification::class, 1);
    }

    public function test_activity_log_filters_by_action_group(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        foreach (['evaluation.created', 'evaluation.decided', 'competitor.created'] as $action) {
            ActivityLog::query()->create(['store_id' => $store->id, 'user_id' => $owner->id, 'action' => $action, 'created_at' => now()]);
        }

        $this->actingAs($owner)->get('/store/activity?action=evaluation')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    }
}
