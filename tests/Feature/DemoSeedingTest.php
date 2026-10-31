<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\AiUsage;
use App\Models\Design;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Ai;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStoresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-only-key']);
    }

    public function test_seeding_twice_preserves_all_rows_and_files_and_never_calls_gemini(): void
    {
        $originalAi = app(Ai::class);
        $this->assertTrue($originalAi->isLive());

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('stores', 4);
        $this->assertDatabaseCount('designs', 9);
        $this->assertGreaterThan(0, Evaluation::query()->where('status', 'completed')->count());
        $this->assertFalse(Evaluation::query()->where('status', '!=', 'completed')->exists());
        $this->assertFalse(AiUsage::query()->where('provider', 'gemini')->whereNull('meta->seed')->exists());
        $this->assertSame($originalAi, app(Ai::class));

        $lama = User::query()->where('email', 'lama@demo.sa')->firstOrFail();
        $this->assertTrue($lama->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $lama->password));
        $this->assertTrue($lama->ownedStores()->firstOrFail()->isActive());

        // A customized seeded store must still be recognized on subsequent runs.
        $lama->ownedStores()->firstOrFail()->update(['name' => 'Renamed demo', 'domain' => 'changed.example']);
        $tables = ['users', 'stores', 'store_user', 'products', 'product_sales', 'competitors',
            'competitor_products', 'social_posts', 'trends', 'customer_reviews', 'evaluations',
            'evaluation_batches', 'design_requests', 'designs', 'ai_usages', 'activity_logs',
            'notifications', 'seasonal_events'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->all()])->all();
        $files = Storage::disk('public')->allFiles();
        $this->travel(1)->days();
        $this->seed(DatabaseSeeder::class);
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->all(), $table.' changed on rerun');
        }
        $this->assertSame($files, Storage::disk('public')->allFiles());
        Http::assertNothingSent();

        $this->post('/login', ['email' => 'lama@demo.sa', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($lama);
    }

    public function test_existing_accounts_stores_calendar_and_images_are_preserved_when_missing_demos_are_added(): void
    {
        $admin = User::factory()->create(['email' => 'admin@mojo.ai', 'role' => User::ROLE_ADMIN, 'password' => 'Existing-admin-password']);
        $lama = User::factory()->create(['email' => 'lama@demo.sa', 'password' => 'Existing-owner-password']);
        $real = app(CreateStore::class)->handle($lama, ['name' => 'My existing store']);
        $real->update(['settings' => ['custom' => true], 'connection_status' => 'connected']);
        $product = Product::query()->create(['store_id' => $real->id, 'name' => 'Real product', 'price' => 127]);

        // Stores made by the old seeder have no demo marker and must also be kept.
        $hadaya = User::factory()->create(['email' => 'hadaya@demo.sa']);
        $legacy = app(CreateStore::class)->handle($hadaya, ['name' => 'Customized name', 'domain' => 'baitalhadaya.com']);
        $legacy->update(['status' => 'suspended', 'settings' => ['accept_threshold' => 87]]);
        $rose = User::factory()->create(['email' => 'rose@demo.sa', 'status' => User::STATUS_ACTIVE]);
        $event = SeasonalEvent::query()->create([
            'season_key' => 'ramadan', 'name_ar' => 'تقويم مخصص', 'name_en' => 'Custom calendar',
            'starts_on' => '2027-02-09', 'ends_on' => '2027-03-10', 'prep_weeks' => 3, 'is_active' => false,
        ]);
        $models = [$admin, $lama, $real, $product, $legacy, $rose, $event];
        $before = array_map(fn ($model) => $model->fresh()->getRawOriginal(), $models);
        Storage::disk('public')->put('designs/existing.png', 'existing design');
        Storage::disk('public')->put('evaluations/existing.png', 'existing evaluation');

        $this->seed(DatabaseSeeder::class);

        foreach ($models as $index => $model) {
            $this->assertSame($before[$index], $model->fresh()->getRawOriginal());
        }
        $this->assertSame(2, $lama->ownedStores()->count());
        $demo = $lama->ownedStores()->where('settings->demo_seed', 'lama@demo.sa')->firstOrFail();
        $this->assertGreaterThan(0, $demo->products()->count());
        $this->assertSame(1, $hadaya->ownedStores()->count());
        $this->assertDatabaseCount('stores', 5);
        $this->assertSame('existing design', Storage::disk('public')->get('designs/existing.png'));
        $this->assertSame('existing evaluation', Storage::disk('public')->get('evaluations/existing.png'));
        Http::assertNothingSent();
    }

    public function test_failed_store_creation_rolls_back_rows_and_removes_only_its_new_files(): void
    {
        $originalAi = app(Ai::class);
        Storage::disk('public')->put('designs/keep.png', 'keep');
        Storage::disk('public')->put('evaluations/keep.png', 'keep');
        $fail = true;
        Design::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated seed failure');
            }
        });

        try {
            $this->seed(DemoStoresSeeder::class);
            $this->fail('Expected the injected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated seed failure', $e->getMessage());
        }

        foreach (['users', 'stores', 'store_user', 'products', 'product_sales', 'evaluations', 'designs', 'ai_usages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertEqualsCanonicalizing(['designs/keep.png', 'evaluations/keep.png'], Storage::disk('public')->allFiles());
        $this->assertSame($originalAi, app(Ai::class));
        $fail = false;
        $this->seed(DemoStoresSeeder::class);
        $this->assertDatabaseCount('stores', 4);
        $this->assertDatabaseCount('designs', 9);
        Http::assertNothingSent();
    }
}
