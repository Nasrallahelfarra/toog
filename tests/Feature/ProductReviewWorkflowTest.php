<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateImprovedDesign;
use App\Jobs\ReviewImprovedDesign;
use App\Models\DesignImprovement;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\Evaluation\EvaluationService;
use App\Services\Evaluation\ProductReview;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'inertia.ssr.enabled' => false,
            'mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key',
            'mojo.ai.gemini.text_model' => 'test-text', 'mojo.ai.gemini.image_model' => 'test-image']);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $image = imagecreatetruecolor(30, 40);
        ob_start();
        imagepng($image);
        $this->png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put('original.png', $this->png);
    }

    private function text(string $text): array
    {
        return ['ar' => $text, 'en' => $text];
    }

    private function review(): array
    {
        return [
            'summary' => $this->text('Review of the supplied image'), 'confidence' => 'high', 'verdict' => 'improve',
            'missing_data' => [$this->text('Customer testing needed')],
            'dimensions' => array_fill_keys(['design', 'market', 'audience'], ['assessment' => 'needs_work', 'strengths' => $this->text('Clear shape'), 'concerns' => $this->text('Busy pattern')]),
            'recommendations' => [
                ['kind' => 'visual', 'priority' => 'high', 'title' => $this->text('Simplify pattern'), 'instruction' => $this->text('Reduce the pattern density'), 'reason' => $this->text('Crowded surface'), 'effect' => $this->text('May improve legibility'), 'source_ids' => []],
                ['kind' => 'visual', 'priority' => 'low', 'title' => $this->text('Change color'), 'instruction' => $this->text('Make it green'), 'reason' => $this->text('Palette'), 'effect' => $this->text('Hypothesis'), 'source_ids' => ['invented:999']],
                ['kind' => 'business', 'priority' => 'medium', 'title' => $this->text('Review pricing'), 'instruction' => $this->text('Test a lower price'), 'reason' => $this->text('Unknown demand'), 'effect' => $this->text('Test'), 'source_ids' => ['store:history']],
            ],
            'visual_attributes' => ['colors' => ['black'], 'pattern' => 'solid', 'style' => 'minimal'],
        ];
    }

    private function fakeAi(bool $invalidVerification = false, bool $unintendedChange = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($invalidVerification, $unintendedChange) {
            if (str_contains($request->url(), 'test-image:')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png)]]]]]]]);
            }
            $verification = str_contains($request['systemInstruction']['parts'][0]['text'], 'independent visual quality reviewer');
            $data = $verification ? [
                'summary' => $this->text('Compared the two images'), 'preserved' => ! $unintendedChange, 'confidence' => 'high',
                'checks' => $invalidVerification ? [] : [['id' => 'r1', 'status' => 'applied', 'detail' => $this->text('Pattern simplified')]],
                'issues' => $unintendedChange ? [$this->text('Brand mark changed')] : [],
                'visual_attributes' => ['colors' => ['black'], 'pattern' => 'solid', 'style' => 'minimal'],
            ] : ['explanation_ar' => 'Explanation', 'explanation_en' => 'Explanation', 'top_factors' => [], 'cultural_flags' => [], 'advice' => [], 'specialist_review' => $this->review()];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]]);
        });
    }

    private function evaluation(): Evaluation
    {
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Review store']);
        $context = ['audience' => 'Adults', 'coverage' => ['store_products' => 0, 'classified_products' => 0, 'competitor_sample' => 0], 'sources' => [['id' => 'store:history', 'kind' => 'store', 'title' => 'Store history', 'url' => null]]];
        $evaluation = Evaluation::query()->create([
            'store_id' => $store->id, 'user_id' => $store->owner_id, 'name' => 'Product', 'status' => 'completed', 'image_path' => 'original.png',
            'price' => 100, 'colors' => ['green'], 'pattern' => 'floral', 'style' => 'luxury', 'target_audience' => 'Adults',
            'specialist_review' => app(ProductReview::class)->normalize($this->review(), true, true, $context),
        ]);
        $evaluation->update(['root_id' => $evaluation->id]);

        return $evaluation;
    }

    private function payload(Evaluation $evaluation): array
    {
        return ['request_key' => (string) Str::uuid(), 'review_version' => $evaluation->specialist_review['version'], 'recommendation_ids' => ['r1']];
    }

    private function enqueue(Evaluation $evaluation): DesignImprovement
    {
        $this->actingAs($evaluation->store->owner)->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertSessionHasNoErrors()->assertRedirect();

        return DesignImprovement::query()->latest()->firstOrFail();
    }

    public function test_selected_edits_are_deduplicated_verified_and_saved_as_a_new_version(): void
    {
        $this->fakeAi();
        $evaluation = $this->evaluation();
        $payload = $this->payload($evaluation);
        foreach (range(1, 2) as $i) {
            $this->actingAs($evaluation->store->owner)->post('/store/evaluations/'.$evaluation->id.'/improvements', $payload)->assertSessionHasNoErrors();
        }
        Queue::assertPushed(GenerateImprovedDesign::class, 1);
        $run = DesignImprovement::query()->firstOrFail();
        $this->assertCount(1, $run->recommendations);
        app()->call([new GenerateImprovedDesign($run->id), 'handle']);
        app()->call([new GenerateImprovedDesign($run->id), 'handle']);
        Queue::assertPushed(ReviewImprovedDesign::class, 1);
        $this->assertSame('review_queued', $run->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'test-image:')
            && isset($request['contents'][0]['parts'][0]['inlineData'])
            && str_contains($request['contents'][0]['parts'][1]['text'], 'Reduce the pattern density')
            && ! str_contains($request['contents'][0]['parts'][1]['text'], 'Make it green'));
        app()->call([new ReviewImprovedDesign($run->id), 'handle']);
        app()->call([new ReviewImprovedDesign($run->id), 'handle']);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('passed', $run->verification['status']);
        $this->assertSame(['black'], $run->result->colors);
        $this->assertSame('solid', $run->result->pattern);
        $this->assertSame('minimal', $run->result->style);
        $this->assertSame(2, $run->result->version);
        $this->assertSame($evaluation->id, $run->result->parent_id);
        $this->assertSame($evaluation->id, $run->result->root_id);
        $this->assertSame('100.00', $run->result->price);
        $this->assertSame('original.png', $evaluation->fresh()->image_path);
        $this->assertSame(['green'], $evaluation->fresh()->colors);
        Storage::disk('public')->assertExists('original.png');
        $this->assertDatabaseCount('designs', 1);
        $this->assertDatabaseCount('evaluations', 2);
        $this->get('/store/evaluations/'.$evaluation->id)->assertInertia(fn (Assert $page) => $page
            ->where('improvements.0.status', 'completed')->where('improvements.0.verification.status', 'passed')->where('versions.1.version', 2));
        $this->post('/store/improvements/'.$run->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('approved', $run->fresh()->design->status);
        $this->assertNotNull($run->fresh()->approved_at);
        $this->assertNull($run->fresh()->result->final_decision);
    }

    public function test_invalid_or_business_edits_and_stale_reviews_are_rejected(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner);
        foreach ([['recommendation_ids' => ['r3']], ['recommendation_ids' => ['made-up']], ['review_version' => (string) Str::uuid()]] as $change) {
            $this->post('/store/evaluations/'.$evaluation->id.'/improvements', [...$this->payload($evaluation), ...$change])->assertSessionHasErrors('improvement');
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('design_improvements', 0);
        Http::assertNothingSent();
    }

    public function test_cross_store_access_is_rejected_for_every_improvement_action(): void
    {
        $evaluation = $this->evaluation();
        $run = DesignImprovement::query()->create([
            'store_id' => $evaluation->store_id, 'evaluation_id' => $evaluation->id, 'user_id' => $evaluation->user_id,
            'request_key' => (string) Str::uuid(), 'status' => 'queued',
            'recommendations' => [$evaluation->specialist_review['recommendations'][0]],
            'source_snapshot' => ['name' => $evaluation->name], 'source_image_path' => 'original.png',
        ]);
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $this->actingAs($other->owner);
        $this->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertNotFound();
        $this->post('/store/improvements/'.$run->id.'/retry')->assertNotFound();
        $this->post('/store/improvements/'.$run->id.'/approve')->assertNotFound();
        $this->get('/store/evaluations/'.$evaluation->id)->assertNotFound();
    }

    public function test_failed_verification_retains_the_image_and_retry_does_not_generate_again(): void
    {
        $this->fakeAi(invalidVerification: true);
        $run = $this->enqueue($this->evaluation());
        app()->call([new GenerateImprovedDesign($run->id), 'handle']);
        $job = new ReviewImprovedDesign($run->id);
        try {
            app()->call([$job, 'handle']);
            $this->fail('Invalid verification must fail');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }
        $run->refresh();
        $path = $run->design->image_path;
        $this->assertSame('failed', $run->status);
        $this->assertSame('unavailable', $run->verification['status']);
        Storage::disk('public')->assertExists($path);
        $this->post('/store/improvements/'.$run->id.'/approve')->assertSessionHasErrors('improvement');
        $this->fakeAi();
        $this->post('/store/improvements/'.$run->id.'/retry')->assertSessionHasNoErrors();
        app()->call([new ReviewImprovedDesign($run->id), 'handle']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($path, $run->fresh()->design->image_path);
        $this->assertDatabaseCount('designs', 1);
        Queue::assertPushed(GenerateImprovedDesign::class, 1);
    }

    public function test_unintended_changes_require_explicit_acknowledgement(): void
    {
        $this->fakeAi(unintendedChange: true);
        $run = $this->enqueue($this->evaluation());
        app()->call([new GenerateImprovedDesign($run->id), 'handle']);
        app()->call([new ReviewImprovedDesign($run->id), 'handle']);
        $this->assertSame('attention', $run->fresh()->verification['status']);
        $this->post('/store/improvements/'.$run->id.'/approve')->assertSessionHasErrors('improvement');
        $this->post('/store/improvements/'.$run->id.'/approve', ['acknowledge_issues' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $run->fresh()->design->status);
    }

    public function test_unavailable_ai_does_not_produce_a_fake_review_or_accept_edits(): void
    {
        config(['mojo.ai.gemini.api_key' => null]);
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner)->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertSessionHasErrors('improvement');
        app(EvaluationService::class)->run($evaluation, notify: false);
        $this->assertSame('unavailable', $evaluation->fresh()->specialist_review['status']);
        $this->assertEmpty($evaluation->fresh()->specialist_review['recommendations']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_missing_evidence_caps_confidence_and_missing_image_disables_visual_recommendations(): void
    {
        $this->fakeAi();
        $evaluation = $this->evaluation();
        app(EvaluationService::class)->run($evaluation, notify: false);
        $review = $evaluation->fresh()->specialist_review;
        $this->assertSame('low', $review['confidence']);
        $this->assertSame([], $review['recommendations'][1]['source_ids']);
        $evaluation->update(['image_path' => null]);
        app(EvaluationService::class)->run($evaluation, notify: false);
        $review = $evaluation->fresh()->specialist_review;
        $this->assertFalse($review['image_reviewed']);
        $this->assertSame('not_assessed', $review['dimensions']['design']['assessment']);
        $this->assertCount(1, $review['recommendations']);
        $this->assertSame('business', $review['recommendations'][0]['kind']);
    }

    public function test_abandoned_work_expires_and_retry_is_idempotent(): void
    {
        $evaluation = $this->evaluation();
        $run = $this->enqueue($evaluation);
        DesignImprovement::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes(16)]);
        $this->get('/store/evaluations/'.$evaluation->id)->assertInertia(fn (Assert $page) => $page
            ->where('improvements.0.status', 'failed')->where('improvements.0.error', 'timed_out'));
        $this->post('/store/improvements/'.$run->id.'/retry')->assertSessionHasNoErrors();
        $this->post('/store/improvements/'.$run->id.'/retry')->assertSessionHasNoErrors();
        Queue::assertPushed(GenerateImprovedDesign::class, 2); // initial enqueue + one retry
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_visual_review_fills_missing_attributes_without_replacing_merchant_input(): void
    {
        $this->fakeAi();
        $evaluation = $this->evaluation();
        $evaluation->update(['colors' => [], 'pattern' => null, 'style' => 'luxury']);
        app(EvaluationService::class)->run($evaluation, notify: false);
        $evaluation->refresh();
        $this->assertSame(['black'], $evaluation->colors);
        $this->assertSame('solid', $evaluation->pattern);
        $this->assertSame('luxury', $evaluation->style);
        $this->assertSame('available', $evaluation->specialist_review['status']);
    }

    public function test_missing_source_and_unavailable_queue_or_budget_do_not_start_paid_work(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner);
        config(['queue.default' => 'sync']);
        $this->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertSessionHasErrors('improvement');
        config(['queue.default' => 'database']);
        $evaluation->store->update(['ai_monthly_budget' => 0]);
        app(CurrentStore::class)->require()->refresh();
        $this->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertSessionHasErrors('improvement');
        $evaluation->store->update(['ai_monthly_budget' => null]);
        app(CurrentStore::class)->require()->refresh();
        Storage::disk('public')->delete('original.png');
        $this->post('/store/evaluations/'.$evaluation->id.'/improvements', $this->payload($evaluation))->assertSessionHasErrors('improvement');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('design_improvements', 0);
    }
}
