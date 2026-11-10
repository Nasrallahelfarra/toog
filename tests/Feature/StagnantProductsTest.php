<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesigns;
use App\Jobs\RecommendStagnantProduct;
use App\Models\DesignRequest;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\SeasonalEvent;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Analytics\StorePerformance;
use App\Services\Catalog\RecommendationProgress;
use App\Services\Design\DesignService;
use App\Services\Design\PromptBuilder;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        config(['queue.default' => 'database', 'inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key']);
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

    private function requestRecommendation(Product $product, array $options = []): ProductRecommendation
    {
        $this->actingAs($product->store->owner)->from('/store/stagnant')->post("/store/stagnant/{$product->id}/recommendation", $options)
            ->assertSessionHasNoErrors()->assertRedirect('/store/stagnant');

        return ProductRecommendation::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }

    private function complete(Product $product, ?array $answer = null): ProductRecommendation
    {
        $run = $this->requestRecommendation($product);
        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->withArgs(function ($operation, $store, $system, $prompt, $schema, $fallback, $images, $userId, $timeoutSeconds) use ($product, $run) {
            $context = json_decode(explode("\nImage available:", $prompt)[0], true);
            $this->assertSame('product_recovery', $operation);
            $this->assertStringContainsString('product recovery specialist', $system);
            $this->assertStringContainsString('ALWAYS propose THREE distinct new design directions for the product itself', $system);
            $this->assertStringContainsString('category_best_sellers', $system);
            // Gemini rejects (400 INVALID_ARGUMENT) a spec nested in each
            // recommendation or a colour enum inside an array; keep both out.
            $this->assertArrayNotHasKey('design', $schema['properties']['recommendations']['items']['properties']);
            $this->assertArrayNotHasKey('enum', $schema['properties']['designs']['items']['properties']['colors']['items']);
            $this->assertStringContainsString('dusty_pink', $prompt);
            $this->assertStringContainsString('Never suggest changes to the photo or the model', $system);
            $this->assertStringContainsString('shopper understanding, trust or purchase consideration', $prompt);
            $this->assertSame(120, $timeoutSeconds);
            $presentation = $run->fresh()->presentation();
            $this->assertSame('processing', $presentation['status']);
            $this->assertSame('model', $presentation['phase']);
            $this->assertNotNull($presentation['started_at']);
            $this->assertNull($presentation['review']);
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

    public function test_stagnant_and_at_risk_counts_filters_and_dashboard_use_the_same_classes(): void
    {
        $stagnant = $this->product();
        $risk = $stagnant->store->products()->create(['name' => 'Overstock seller', 'sku' => 'RISK', 'stock' => 100,
            'units_sold' => 10, 'performance' => Product::AT_RISK, 'turnover_days' => 300]);
        $this->product(['performance' => Product::AT_RISK, 'name' => 'Other store risk']);
        $this->actingAs($stagnant->store->owner)->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2)->where('summary.stagnant', 1)->where('summary.at_risk', 1)
            ->where('summary.needs_reclassification', true)->has('products.data', 2));
        $this->get('/store/stagnant?class=at_risk&search=RISK')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('products.data.0.id', $risk->id)
            ->where('products.data.0.performance', 'at_risk')->where('products.data.0.classification_reason', 'slow_turnover')
            ->where('summary.stagnant', 1)->where('summary.at_risk', 1));
        $this->get('/store/stagnant?class=stagnant')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('products.data.0.id', $stagnant->id));
        $this->get('/store/performance?class=at_risk')->assertInertia(fn (Assert $page) => $page
            ->where('summary.classes.at_risk.count', 1)->where('summary.classes.stagnant.count', 1)
            ->has('products.data', 1)->where('products.data.0.id', $risk->id));
        $this->get('/store')->assertInertia(fn (Assert $page) => $page
            ->where('stagnant.stagnant', 1)->where('stagnant.at_risk', 1)->has('atRisk', 1)->where('atRisk.0.id', $risk->id));
    }

    public function test_at_risk_products_can_request_the_same_expert_workflow(): void
    {
        $run = $this->complete($this->product(['performance' => Product::AT_RISK]));
        $this->assertSame('completed', $run->status);
        $this->assertSame(Product::AT_RISK, $run->snapshot['performance']);
    }

    public function test_risk_total_is_not_capped_by_the_dashboard_preview_limit(): void
    {
        $product = $this->product();
        foreach (array_chunk(array_fill(0, 1001, ['store_id' => $product->store_id, 'name' => 'Risk', 'stock' => 1, 'performance' => Product::AT_RISK]), 100) as $rows) {
            DB::table('products')->insert($rows);
        }
        $performance = app(StorePerformance::class);
        $this->assertSame(1001, $performance->atRiskCount($product->store));
        $this->assertCount(6, $performance->atRisk($product->store));
    }

    public function test_ai_recommendation_is_saved_with_the_actual_reference_and_reused(): void
    {
        $product = $this->product();
        $run = $this->complete($product);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->review['image_reviewed']);
        $this->assertCount(2, $run->review['recommendations']);
        $this->assertArrayNotHasKey('_progress', $run->presentation()['review']);
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
        $this->assertStringContainsString('changes the design of the product itself', $prompt);
        $this->assertStringContainsString('Keep the photograph itself exactly as it is', $prompt);
        $this->assertStringNotContainsString('Colour palette:', $prompt);
        Queue::assertPushed(GenerateDesigns::class, 1);
    }

    private function studioPayload(ProductRecommendation $run): array
    {
        return ['product_recommendation_id' => $run->id, 'recommendation_key' => 'r1', 'type' => 'modification',
            'title' => $run->snapshot['name'], 'prompt' => $run->review['recommendations'][0]['instruction']['ar'],
            'category' => 'Bags', 'output_type' => 'product_photo', 'aspect_ratio' => '1:1', 'variants_count' => 1];
    }

    public function test_three_visual_alternatives_are_saved_and_only_the_chosen_one_reaches_studio(): void
    {
        $answer = $this->review();
        $visual = $answer['recommendations'][0];
        $answer['recommendations'] = [];
        foreach (['Use a clean backdrop', 'Create a restrained palette', 'Simplify the pattern'] as $instruction) {
            $answer['recommendations'][] = [...$visual, 'title' => ['ar' => $instruction, 'en' => $instruction],
                'instruction' => ['ar' => $instruction, 'en' => $instruction]];
        }
        $run = $this->complete($this->product(), $answer);
        $this->assertCount(3, $run->review['recommendations']);
        $this->get("/store/studio?recovery={$run->id}&action=r2")->assertInertia(fn (Assert $page) => $page
            ->where('source.prompt', 'Create a restrained palette')->where('source.action', 'r2')
            ->where('source.image_url', Storage::disk('public')->url($run->source_image_path)));
        $this->post('/store/studio', [...$this->studioPayload($run), 'recommendation_key' => 'r2', 'prompt' => 'Create a restrained palette'])
            ->assertSessionHasNoErrors()->assertRedirect('/store/studio');
        $design = DesignRequest::query()->firstOrFail();
        $this->assertSame('Create a restrained palette', $design->prompt);
        $this->assertSame($run->source_image_path, $design->source_image_path);
        $this->assertSame($run->id, $design->product_recommendation_id);
        Queue::assertPushed(GenerateDesigns::class, 1);
    }

    public function test_each_new_design_keeps_a_clean_cut_style_colour_and_details_spec(): void
    {
        $text = fn (string $value) => ['ar' => $value, 'en' => $value];
        $answer = $this->review();
        // One spec per visual recommendation, in order; extra specs are ignored.
        $answer['designs'] = [['cut' => $text('A-line midi with puffed sleeves'), 'style' => 'luxury', 'pattern' => 'not-a-pattern',
            'colors' => ['dusty_pink', 'neon-green', 'gold', 'dusty_pink'], 'details' => $text('Pearl trim on the collar'), 'injected' => 'drop me'],
            ['cut' => $text('Should be ignored'), 'style' => 'modern', 'pattern' => null, 'colors' => [], 'details' => $text('x')]];
        $run = $this->complete($this->product(), $answer);

        [$design, $business] = $run->review['recommendations'];
        $this->assertSame(['cut' => $text('A-line midi with puffed sleeves'), 'style' => 'luxury', 'pattern' => null,
            'colors' => ['dusty_pink', 'gold'], 'details' => $text('Pearl trim on the collar')], $design['design']);
        $this->assertNull($business['design']);
    }

    public function test_an_incomplete_design_spec_is_dropped_without_losing_the_design(): void
    {
        $text = fn (string $value) => ['ar' => $value, 'en' => $value];
        $broken = $this->review();
        $broken['designs'] = [['cut' => $text('Missing details'), 'style' => 'luxury', 'pattern' => null, 'colors' => []]];
        $design = $this->complete($this->product(), $broken)->review['recommendations'][0];
        $this->assertSame('visual', $design['kind']);
        $this->assertNull($design['design']);
    }

    public function test_queued_work_is_distinguished_from_ai_processing_and_is_not_duplicated_when_waiting(): void
    {
        $product = $this->product();
        $run = $this->requestRecommendation($product);
        // Use real database queue payloads without running any external work.
        $queue = Queue::getFacadeRoot()->queue->connection('database');
        $busy = $queue->push(new RecommendStagnantProduct(99999));
        DB::table('jobs')->where('id', $busy)->update(['reserved_at' => now()->timestamp]);
        $queue->push(new RecommendStagnantProduct(99998));
        $queue->push(new RecommendStagnantProduct($run->id));
        $run->forceFill(['updated_at' => now()->subMinutes(20)])->save();
        $this->app->forgetInstance(RecommendationProgress::class);
        $state = $run->presentation();
        $this->assertSame('pending', $state['status']);
        $this->assertSame('queued', $state['phase']);
        $this->assertNull($state['started_at']);
        $this->assertTrue($state['queue']['found']);
        $this->assertSame(1, $state['queue']['ahead']);
        $this->assertSame('RecommendStagnantProduct', $state['queue']['busy']);
        $this->assertSame($run->id, $this->requestRecommendation($product)->id);
        Queue::assertPushed(RecommendStagnantProduct::class, 1);
    }

    public function test_a_lost_job_becomes_retryable_without_waiting_for_a_fifteen_minute_spinner(): void
    {
        $product = $this->product();
        $run = $this->requestRecommendation($product);
        $run->forceFill(['updated_at' => now()->subMinutes(3)])->save();
        $this->assertSame('job_lost', $run->presentation()['error']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotSame($run->id, $this->requestRecommendation($product)->id);
    }

    public function test_new_options_require_an_explicit_request_and_repeated_requests_are_deduplicated(): void
    {
        $product = $this->product();
        $saved = $this->complete($product);
        $this->assertSame($saved->id, $this->requestRecommendation($product)->id);
        $new = $this->requestRecommendation($product, ['replace_recommendation_id' => $saved->id]);
        $this->assertNotSame($saved->id, $new->id);
        $this->assertSame('completed', $saved->fresh()->status);
        // Even a late duplicate after completion cannot regenerate an already-replaced result.
        $new->update(['status' => 'completed', 'review' => $saved->review]);
        $this->assertSame($new->id, $this->requestRecommendation($product, ['replace_recommendation_id' => $saved->id])->id);
        $this->assertDatabaseCount('product_recommendations', 2);
        Queue::assertPushed(RecommendStagnantProduct::class, 2);
    }

    public function test_inline_queue_is_rejected_without_running_ai_in_the_http_request(): void
    {
        config(['queue.default' => 'sync']);
        $run = $this->requestRecommendation($this->product());
        $this->assertSame('failed', $run->status);
        $this->assertSame('queue_unavailable', $run->error);
        Queue::assertNothingPushed();
    }

    public function test_timed_out_processing_is_persisted_and_late_ai_results_cannot_replace_it(): void
    {
        $run = $this->requestRecommendation($this->product());
        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->andReturnUsing(function () use ($run) {
            // Simulate a worker stuck beyond its deadline while the browser checks status.
            $this->travel(8)->minutes();
            $state = $run->fresh()->presentation();
            $this->assertSame('failed', $state['status']);
            $this->assertSame('timed_out', $state['error']);

            return new AiJsonResult($this->review(), true, 0.01);
        });
        $this->app->instance(Ai::class, $ai);
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertArrayNotHasKey('recommendations', $run->fresh()->review);
        $this->travelBack();
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

    public function test_best_sellers_get_a_growth_recommendation_instead_of_a_recovery(): void
    {
        $product = $this->product(['performance' => Product::WINNER]);
        $run = $this->requestRecommendation($product);
        Queue::assertPushed(RecommendStagnantProduct::class, 1);

        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->withArgs(function ($operation, $store, $system, $prompt) {
            $context = json_decode(explode("\nImage available:", $prompt)[0], true);
            $this->assertStringContainsString('product growth specialist', $system);
            $this->assertStringNotContainsString('slow-moving', $system);
            $this->assertStringStartsWith('grow and protect', $context['goal']);

            return true;
        })->andReturn(new AiJsonResult($this->review(), true, 0.01));
        $this->app->instance(Ai::class, $ai);
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_marketing_plan_is_grounded_in_market_facts_and_saved_clean(): void
    {
        $product = $this->product();
        $store = $product->store;
        Product::query()->create(['store_id' => $store->id, 'name' => 'Best bag', 'category' => 'Bags', 'performance' => Product::WINNER, 'price' => 80, 'features' => ['colors' => ['beige'], 'pattern' => 'solid']]);
        Product::query()->create(['store_id' => $store->id, 'name' => 'Other bag', 'category' => 'Bags', 'performance' => Product::AVERAGE, 'price' => 140]);
        SeasonalEvent::query()->create(['store_id' => $store->id, 'season_key' => 'national_day', 'name_ar' => 'اليوم الوطني', 'name_en' => 'National Day',
            'starts_on' => now()->addDays(30), 'ends_on' => now()->addDays(31), 'prep_weeks' => 3, 'is_active' => true]);
        $text = fn ($value) => ['ar' => $value, 'en' => $value];
        $plan = [
            'angle' => $text('A ready gift for the occasion'),
            'copy' => ['headline' => $text('شنطة تكمّل إطلالتك'), 'body' => $text('خامة تدوم وتفاصيل ناعمة تناسب يومك.'), 'cta' => $text('اطلبيها الحين')],
            'hashtags' => ['#شنط نسائية', 'اليوم_الوطني', '#شنط نسائية'],
            'offer' => ['type' => 'bundle', 'value' => $text('قطعتين بـ 180 ر.س'), 'duration_days' => 90, 'rationale' => $text('Stock lasts a year at the current pace')],
            'channels' => [['channel' => 'snapchat', 'idea' => $text('A 6-second hook on the clasp')], ['channel' => 'whatsapp', 'idea' => $text('Broadcast to past buyers')]],
            'timing' => ['occasion' => $text('اليوم الوطني'), 'when' => $text('Start three weeks before')],
            'kpi' => $text('Units sold in 14 days against the last 90'),
        ];

        $run = $this->requestRecommendation($product);
        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->withArgs(function ($operation, $store, $system, $prompt, $schema) {
            $context = json_decode(explode("\nImage available:", $prompt)[0], true)['market_context'];
            $this->assertStringContainsString('marketer for the Saudi market', $system);
            $this->assertEquals(100, $context['pricing']['price']);
            $this->assertEquals(100, $context['pricing']['category_median']);
            $this->assertSame(33, $context['pricing']['cheaper_peers_share']);
            $this->assertSame(['beige' => 1], $context['category_best_sellers']['colors']);
            $this->assertEquals(5000, $context['inventory']['stock_value']);
            $this->assertSame('اليوم الوطني', $context['occasions'][0]['name_ar']);
            $this->assertContains('marketing_plan', $schema['required']);

            return true;
        })->andReturn(new AiJsonResult([...$this->review(), 'marketing_plan' => $plan], true, 0.01));
        $this->app->instance(Ai::class, $ai);
        app()->call([new RecommendStagnantProduct($run->id), 'handle']);

        $saved = $run->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertSame(60, $saved->review['marketing_plan']['offer']['duration_days']);
        $this->assertSame(['#شنط_نسائية', '#اليوم_الوطني'], $saved->review['marketing_plan']['hashtags']);
        $this->assertSame('snapchat', $saved->review['marketing_plan']['channels'][0]['channel']);
        $this->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page->where('products.data.0.recovery.review.marketing_plan.offer.type', 'bundle'));
    }

    public function test_an_invalid_marketing_plan_is_dropped_without_losing_the_review(): void
    {
        $product = $this->product();
        $run = $this->complete($product, [...$this->review(), 'marketing_plan' => ['offer' => ['type' => 'lottery']]]);

        $this->assertSame('completed', $run->status);
        $this->assertNull($run->review['marketing_plan']);
        $this->assertCount(2, $run->review['recommendations']);
    }
}
