<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Team\TeamReport;
use App\Support\ExcludedProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Accessories are left out of the platform: the store's own products and
 * the ones read from competitors. They are marked and skipped, never
 * deleted, so nothing is lost if the rule ever changes.
 */
class AccessoriesAreLeftOutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $dress;

    private Product $necklace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
        $this->dress = $this->store->products()->create(['name' => 'فستان أسود ون شولدر بدانتيل', 'external_id' => 'd', 'category' => 'فساتين مناسبات', 'price' => 400]);
        $this->necklace = $this->store->products()->create(['name' => 'طقم اكسسوار بعقد زيركون هندسي', 'external_id' => 'n', 'category' => 'اطقم اكسسوارات نسائية', 'price' => 249]);

        foreach ([[$this->dress, 5], [$this->necklace, 40]] as [$product, $units]) {
            ProductSale::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'product_id' => $product->id,
                'period' => now()->startOfMonth(), 'orders' => $units, 'units' => $units, 'revenue' => $units * 100]);
        }
    }

    public function test_an_accessory_is_known_by_its_section_or_name_but_a_garment_is_not(): void
    {
        foreach ([
            ['اطقم اكسسوارات نسائية', 'طقم اكسسوار بعقد زيركون هندسي'],
            ['إكسسوارات', 'عقد لؤلؤ'],
            [null, 'أكسسوار شعر ذهبي'],
            ['Accessories', 'Pearl necklace'],
            ['عبايات', 'اكسسورات عباية'],
        ] as [$category, $name]) {
            $this->assertTrue(ExcludedProducts::matches($category, $name), "{$category} / {$name}");
        }

        foreach ([
            ['فساتين مناسبات', 'فستان أسود ون شولدر بدانتيل'],
            // A garment sold with accessories, or filed beside them, is a garment.
            ['إكسسوارات', 'فستان سهرة مع طقم اكسسوار'],
            // A set of clothes is not an accessory set.
            ['أطقم', 'طقم قطعتين كتان'],
            [null, null],
        ] as [$category, $name]) {
            $this->assertFalse(ExcludedProducts::matches($category, $name), "{$category} / {$name}");
        }
    }

    public function test_the_accessory_is_marked_not_deleted_and_no_query_sees_it(): void
    {
        $this->assertSame(1, Product::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(1, $this->store->products()->count());
        $this->assertNull(Product::query()->find($this->necklace->id));
        $this->assertSame(1, ProductSale::query()->where('store_id', $this->store->id)->count());

        // The row and its sales are still there.
        $this->assertTrue(Product::withExcluded()->findOrFail($this->necklace->id)->excluded);
        $this->assertSame(2, DB::table('product_sales')->where('store_id', $this->store->id)->count());

        // The mark follows the name: renamed to a garment it comes back.
        Product::withExcluded()->findOrFail($this->necklace->id)->update(['name' => 'فستان مع اكسسوار', 'category' => 'فساتين']);
        $this->assertSame(2, $this->store->products()->count());
        $this->dress->update(['category' => 'اكسسوارات', 'name' => 'سوار ذهبي']);
        $this->assertSame(1, $this->store->products()->count());
    }

    public function test_store_pages_and_totals_leave_the_accessory_out(): void
    {
        $owner = $this->store->owner;
        // Even classified as stagnant, it is not listed.
        DB::table('products')->where('id', $this->necklace->id)->update(['performance' => Product::STAGNANT, 'no_sales_days' => 200]);

        $this->actingAs($owner)->get('/store/catalog?months=3')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.products', 1)
                ->where('summary.units', 5)
                ->has('categories', 1)
                ->where('categories.0.name', 'فساتين مناسبات')
                ->has('products.data', 1)
                ->where('products.data.0.id', $this->dress->id));
        $this->get('/store/performance')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $this->dress->id));
        $this->get('/store/stagnant')
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 0)->has('products.data', 0));
        $this->get("/store/products/{$this->necklace->id}")->assertNotFound();
        $this->get("/store/products/{$this->dress->id}")->assertOk();
    }

    public function test_the_classifier_and_the_team_report_do_not_count_it(): void
    {
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertNotNull($this->dress->fresh()->performance);
        $this->assertNull(DB::table('products')->where('id', $this->necklace->id)->value('performance'));

        $member = User::factory()->create();
        $this->store->members()->attach($member->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode([])]);

        // A member cannot take it, and it is not offered.
        $this->actingAs($member)->post('/store/my-products', ['ids' => [$this->necklace->id]])->assertSessionHasErrors('ids');
        $this->assertNull(DB::table('products')->where('id', $this->necklace->id)->value('assigned_user_id'));
        $this->get('/store/my-products?tab=add')
            ->assertInertia(fn (Assert $page) => $page->where('counts.free', 1)->has('products.data', 1)->where('products.data.0.id', $this->dress->id));

        $report = app(TeamReport::class)->comparison($this->store, 3);
        $this->assertSame(1, $report['totals']['products']);
        $this->assertSame(5, $report['totals']['units']);
    }

    public function test_competitor_accessories_are_left_out_too(): void
    {
        $competitor = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Rival', 'platform' => 'website', 'is_active' => true]);
        $base = ['store_id' => $this->store->id, 'competitor_id' => $competitor->id, 'price' => 100];
        CompetitorProduct::query()->create([...$base, 'external_id' => 'a', 'name' => 'Black abaya', 'category' => 'Abayas']);
        $hidden = CompetitorProduct::query()->create([...$base, 'external_id' => 'b', 'name' => 'طقم اكسسوار', 'category' => 'اكسسوارات']);

        $this->assertSame(['Black abaya'], CompetitorProduct::query()->where('store_id', $this->store->id)->pluck('name')->all());
        $this->assertSame(1, $competitor->products()->count());
        // A scan finds the hidden row again instead of inserting it twice.
        $this->assertTrue(CompetitorProduct::withExcluded()->where('competitor_id', $competitor->id)->where('external_id', 'b')->first()->is($hidden));
    }

    public function test_raw_product_queries_skip_accessories_as_well(): void
    {
        // Model queries skip accessories through a global scope; a query on
        // the table itself has to say so. This catches one that forgets.
        $allowed = [
            // Clears who holds a product when an account is deleted.
            'Models'.DIRECTORY_SEPARATOR.'User.php',
        ];
        $missing = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getRelativePathname(), $allowed, true)) {
                continue;
            }
            $code = $file->getContents();

            if ((str_contains($code, "table('products')") || str_contains($code, "join('products'")) && ! str_contains($code, 'excluded') && ! str_contains($code, 'ProductSale::query()')) {
                $missing[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'These files read the products table directly without skipping excluded products.');
    }
}
