<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesigns;
use App\Jobs\RecommendStagnantProduct;
use App\Models\DesignRequest;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Design\DesignService;
use App\Services\Design\PromptBuilder;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class StagnantProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key']);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::disk('public')->put('catalog.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XcAAAAASUVORK5CYII='));
    }

    private function product(array $attributes = []): Product
    {
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Recovery store']);

        return Product::query()->create([
            'store_id' => $store->id, 'name' => 'Slow product', 'sku' => 'SLOW-1', 'category' => 'Bags',
            'performance' => Product::STAGNANT, 'performance_score' => 12, 'stock' => 50, 'units_sold' => 2,
            'price' => 100, 'turnover_days' => 365, 'features' => ['colors' => ['black'], 'pattern' => 'floral'],
            'image_url' => Storage::disk('public')->url('catalog.png'), ...$attributes,
        ]);
    }

    private function review(): array
    {
        $text = fn ($value) => ['ar' => $value, 'en' => $value];

        return [
            'summary' => $text('Review stock and simplify the pattern'), 'confidence' => 'medium', 'verdict' => 'improve', 'missing_data' => [],
            'dimensions' => array_fill_keys(['design', 'market', 'audience'], ['assessment' => 'needs_work', 'strengths' => $text('Clear shape'), 'concerns' => $text('Busy pattern')]),
            'recommendations' => [
                ['kind' => 'visual', 'priority' => 'high', 'title' => $text('Simplify the pattern'), 'reason' => $text('The pattern is crowded'), 'effect' => $text('May improve readability'), 'instruction' => $text('Reduce pattern density; keep the logo and shape.'), 'source_ids' => []],
                ['kind' => 'business', 'priority' => 'medium', 'title' => $text('Test the offer'), 'reason' => $text('Low sales with high stock'), 'effect' => $text('May help learn demand'), 'instruction' => $text('Test a bundle and compare conversion.'), 'source_ids' => ['store:history']],
            ],
            'visual_attributes' => ['colors' => ['black'], 'pattern' => 'floral', 'style' => 'modern'],
        ];
    }

    private function requestRecommendation(Product $product): ProductRecommendation
    {
        $this->actingAs($product->store->owner)->from('/store/stagnant')->post("/store/stagnant/{$product->id}/recommendation")
            ->assertSessionHasNoErrors()->assertRedirect('/store/stagnant');

        return ProductRecommendation::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }

    private function complete(Product $product, ?array $answer = null): ProductRecommendation
    {
        $run = $this->requestRecommendation($product);
        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->withArgs(function ($operation, $store, $system, $prompt, $schema, $fallback, $images, $userId) use ($product) {
            $context = json_decode(explode("\nImage available:", $prompt)[0], true);
            $this->assertSame('product_recovery', $operation);
            $this->assertStringContainsString('product recovery specialist', $system);
            $this->assertSame(50, $context['product_performance']['stock']);
            $this->assertSame(2, $context['product_performance']['units_sold']);
            $this->assertEmpty($context['sources'][0]['data']['stagnant']);
            $this->assertSame($product->store->owner_id, $userId);
            $this->assertCount($product->image_url ? 1 : 0, $images);

            return true;
        })->andReturn(new AiJsonResult($answer ?? $this->review(), true, 0.01));
        $this->app->instance(Ai::class, $ai);
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);
        // Redelivery must not make a second billable AI call.
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);

        return $run->fresh();
    }

    public function test_dashboard_and_list_show_only_this_stores_stagnant_products(): void
    {
        $product = $this->product();
        $this->product(['name' => 'Other store secret']);
        Product::query()->create(['store_id' => $product->store_id, 'name' => 'Winner', 'performance' => Product::WINNER]);
        $this->actingAs($product->store->owner)->get('/store')->assertInertia(fn (Assert $page) => $page
            ->where('stagnant.total', 1)->has('stagnant.products', 1)->where('stagnant.products.0.id', $product->id));
        $this->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page->component('store/stagnant')
            ->where('summary.total', 1)->where('summary.stock_value', 5000)->has('products.data', 1)
            ->where('products.data.0.image_url', $product->image_url));
        $this->get('/store/stagnant?search=SLOW-1&category=Bags')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/store/stagnant?search=missing')->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('summary.total', 1));
    }

    public function test_requests_are_saved_and_deduplicated_for_the_selected_product(): void
    {
        $product = $this->product();
        $run = $this->requestRecommendation($product);
        $this->requestRecommendation($product);
        Queue::assertPushed(RecommendStagnantProduct::class, 1);
        $this->assertDatabaseCount('product_recommendations', 1);
        $this->assertSame('pending', $run->status);
        $this->assertSame($product->name, $run->snapshot['name']);
    }

    public function test_ai_recommendation_is_saved_with_the_actual_reference_and_reused(): void
    {
        $product = $this->product();
        $run = $this->complete($product);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->review['image_reviewed']);
        $this->assertCount(2, $run->review['recommendations']);
        Storage::disk('public')->assertExists($run->source_image_path);
        $this->assertSame(Storage::disk('public')->get('catalog.png'), Storage::disk('public')->get($run->source_image_path));
        $this->requestRecommendation($product);
        $this->assertDatabaseCount('product_recommendations', 1);
        $this->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page->where('products.data.0.recovery.status', 'completed'));
    }

    public function test_unavailable_ai_is_not_presented_as_an_expert_recommendation(): void
    {
        config(['mojo.ai.gemini.api_key' => null]);
        $run = $this->requestRecommendation($this->product());
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('not_configured', $run->fresh()->error);
        $this->assertNull($run->fresh()->review);
        Queue::assertNotPushed(RecommendStagnantProduct::class);
    }

    public function test_exceeded_budget_is_reported_without_queueing_a_paid_request(): void
    {
        $product = $this->product();
        $product->store->update(['ai_monthly_budget' => 0]);
        $run = $this->requestRecommendation($product);
        $this->assertSame('failed', $run->status);
        $this->assertSame('budget_exceeded', $run->error);
        Queue::assertNotPushed(RecommendStagnantProduct::class);
    }

    public function test_missing_image_prevents_visual_recommendations_but_allows_business_advice(): void
    {
        $run = $this->complete($this->product(['image_url' => null]));
        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->review['image_reviewed']);
        $this->assertCount(1, $run->review['recommendations']);
        $this->assertSame('business', $run->review['recommendations'][0]['kind']);
        $this->get("/store/studio?recovery={$run->id}&action=r1")->assertNotFound();
    }

    public function test_invalid_ai_output_fails_without_invented_advice(): void
    {
        $run = $this->complete($this->product(), ['summary' => 'Malformed']);
        $this->assertSame('failed', $run->status);
        $this->assertSame('unavailable', $run->review['status']);
        $this->assertEmpty($run->review['recommendations']);
    }

    public function test_visual_action_opens_and_submits_the_correct_product_in_studio(): void
    {
        $product = $this->product();
        $run = $this->complete($product);
        $instruction = $run->review['recommendations'][0]['instruction']['ar'];
        $this->get("/store/studio?recovery={$run->id}&action=r1")->assertInertia(fn (Assert $page) => $page
            ->component('store/studio/index')->where('source.name', $product->name)->where('source.id', null)
            ->where('source.recovery_id', $run->id)->where('source.prompt', $instruction)
            ->where('source.image_url', Storage::disk('public')->url($run->source_image_path)));
        $this->post('/store/studio', $this->studioPayload($run))->assertSessionHasNoErrors()->assertRedirect('/store/studio');
        $request = DesignRequest::query()->firstOrFail();
        $this->assertSame($run->source_image_path, $request->source_image_path);
        $this->assertSame($run->id, $request->product_recommendation_id);
        $this->assertNull($request->evaluation_id);
        $attributes = app(DesignService::class)->product($request, null);
        $this->assertSame('Slow product', $attributes['name']);
        $this->assertSame(['black'], $attributes['colors']);
        $prompt = app(PromptBuilder::class)->build($request, $product->store, [], $attributes, true);
        $this->assertStringContainsString($instruction, $prompt);
        $this->assertStringContainsString('Preserve its identity', $prompt);
        $this->assertStringNotContainsString('Colour palette:', $prompt);
        Queue::assertPushed(GenerateDesigns::class, 1);
    }

    private function studioPayload(ProductRecommendation $run): array
    {
        return ['product_recommendation_id' => $run->id, 'recommendation_key' => 'r1', 'type' => 'modification',
            'title' => $run->snapshot['name'], 'prompt' => $run->review['recommendations'][0]['instruction']['ar'],
            'category' => 'Bags', 'output_type' => 'product_photo', 'aspect_ratio' => '1:1', 'variants_count' => 1];
    }

    public function test_other_stores_and_non_visual_actions_cannot_be_used_for_design(): void
    {
        $product = $this->product();
        $run = $this->complete($product);
        $this->get("/store/studio?recovery={$run->id}&action=r2")->assertNotFound();
        $this->post('/store/studio', [...$this->studioPayload($run), 'recommendation_key' => 'r2'])->assertUnprocessable();
        $other = $this->product();
        $this->actingAs($other->store->owner);
        app(CurrentStore::class)->set($other->store);
        $this->post("/store/stagnant/{$product->id}/recommendation")->assertNotFound();
        $this->get("/store/studio?recovery={$run->id}&action=r1")->assertNotFound();
        $this->post('/store/studio', $this->studioPayload($run))->assertNotFound();
        Queue::assertNotPushed(GenerateDesigns::class);
    }

    public function test_missing_saved_reference_requires_upload_instead_of_generating_an_unrelated_product(): void
    {
        $run = $this->complete($this->product());
        Storage::disk('public')->delete($run->source_image_path);
        $this->post('/store/studio', $this->studioPayload($run))->assertSessionHasErrors('source_image');
        Queue::assertNotPushed(GenerateDesigns::class);
    }

    public function test_stale_request_can_be_retried_and_old_job_cannot_run(): void
    {
        $product = $this->product();
        $run = $this->requestRecommendation($product);
        $run->forceFill(['updated_at' => now()->subMinutes(20)])->save();
        $this->assertSame('failed', $run->presentation()['status']);
        $retry = $this->requestRecommendation($product);
        $this->assertNotSame($run->id, $retry->id);
        $this->assertSame('timed_out', $run->fresh()->error);
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        Queue::assertPushed(RecommendStagnantProduct::class, 2);
    }

    public function test_non_stagnant_products_cannot_request_recovery(): void
    {
        $product = $this->product(['performance' => Product::WINNER]);
        $this->actingAs($product->store->owner)->post("/store/stagnant/{$product->id}/recommendation")->assertUnprocessable();
        Queue::assertNotPushed(RecommendStagnantProduct::class);
    }
}
