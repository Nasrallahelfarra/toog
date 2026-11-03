<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\RunEvaluation;
use App\Models\ActivityLog;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\User;
use App\Services\Evaluation\EvaluationService;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvaluationDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);
        Storage::fake('public');
    }

    private function evaluation(array $attributes = []): Evaluation
    {
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Evaluation store']);

        return Evaluation::query()->create([
            'store_id' => $store->id, 'user_id' => $store->owner_id, 'name' => 'Test product',
            'status' => 'completed', 'source' => 'manual', 'category' => 'Bags', ...$attributes,
        ]);
    }

    public static function evaluationStates(): array
    {
        return [['manual', 'completed'], ['manual', 'pending'], ['manual', 'processing'], ['batch', 'failed'], ['design', 'completed']];
    }

    #[DataProvider('evaluationStates')]
    public function test_owner_can_delete_any_evaluation_from_the_list(string $source, string $status): void
    {
        $evaluation = $this->evaluation(['source' => $source, 'status' => $status]);
        $returnTo = '/store/evaluations?source=all&category=Bags&search=Test';
        $this->actingAs($evaluation->store->owner)->delete('/store/evaluations/'.$evaluation->id, ['return_to' => $returnTo])
            ->assertSessionHasNoErrors()->assertRedirect($returnTo);
        $this->assertModelMissing($evaluation);
        $this->assertDatabaseHas('activity_logs', ['action' => 'evaluation.deleted', 'subject_id' => $evaluation->id, 'store_id' => $evaluation->store_id]);
        $this->get($returnTo)->assertInertia(fn (Assert $page) => $page->has('evaluations.data', 0)->where('stats.total', 0)->where('filters.category', 'Bags'));
        $service = $this->mock(EvaluationService::class);
        $service->shouldNotReceive('run');
        (new RunEvaluation($evaluation->id))->handle($service);
        $this->assertModelMissing($evaluation);
    }

    public function test_dashboard_deletion_returns_to_dashboard_and_updates_recent_evaluations(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner)->delete('/store/evaluations/'.$evaluation->id, ['return_to' => '/store'])
            ->assertRedirect('/store');
        $this->get('/store')->assertInertia(fn (Assert $page) => $page->component('store/dashboard')->has('recentEvaluations', 0)->where('kpis.evaluated_this_month', 0));
    }

    public function test_detail_page_deletion_still_returns_to_the_list(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner)->delete('/store/evaluations/'.$evaluation->id)->assertRedirect('/store/evaluations');
        $this->assertModelMissing($evaluation);
    }

    public function test_deleting_last_item_on_last_page_keeps_filters_and_returns_to_valid_page(): void
    {
        $evaluation = $this->evaluation();
        foreach (range(1, 12) as $i) {
            Evaluation::query()->create(['store_id' => $evaluation->store_id, 'name' => 'Test '.$i, 'category' => 'Bags']);
        }
        $url = '/store/evaluations?category=Bags&search=Test&page=2';
        $this->actingAs($evaluation->store->owner)->delete('/store/evaluations/'.$evaluation->id, ['return_to' => $url])->assertRedirect($url);
        $response = $this->get($url)->assertRedirect(route('store.evaluations.index', ['category' => 'Bags', 'search' => 'Test', 'page' => 1]));
        $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page->has('evaluations.data', 12)->where('evaluations.current_page', 1)->where('filters.search', 'Test'));
    }

    public function test_deletion_preserves_products_other_versions_and_shared_design_images(): void
    {
        $evaluation = $this->evaluation(['image_path' => 'shared.png']);
        Storage::disk('public')->put('shared.png', 'shared image');
        $other = Evaluation::query()->create(['store_id' => $evaluation->store_id, 'name' => 'Other version', 'parent_id' => $evaluation->id, 'root_id' => $evaluation->id, 'image_path' => 'shared.png']);
        $product = Product::query()->create(['store_id' => $evaluation->store_id, 'name' => 'Test product']);
        $request = DesignRequest::query()->create(['store_id' => $evaluation->store_id, 'evaluation_id' => $evaluation->id, 'prompt' => 'Improve pattern', 'source_image_path' => 'shared.png']);
        $design = Design::query()->create(['store_id' => $evaluation->store_id, 'evaluation_id' => $evaluation->id, 'design_request_id' => $request->id, 'title' => 'Saved design', 'image_path' => 'shared.png']);
        $this->actingAs($evaluation->store->owner)->delete('/store/evaluations/'.$evaluation->id, ['return_to' => '/store'])->assertRedirect('/store');
        $this->assertModelExists($product);
        $this->assertModelExists($other);
        $this->assertModelExists($design);
        $this->assertModelExists($request);
        $this->assertNull($design->fresh()->evaluation_id);
        $this->assertNull($request->fresh()->evaluation_id);
        Storage::disk('public')->assertExists('shared.png');
    }

    public function test_other_store_owner_cannot_delete_an_evaluation(): void
    {
        $evaluation = $this->evaluation();
        $other = $this->evaluation();
        $this->actingAs($other->store->owner)->delete('/store/evaluations/'.$evaluation->id, ['return_to' => '/store'])->assertNotFound();
        $this->assertModelExists($evaluation);
        $this->assertSame(0, ActivityLog::query()->where('action', 'evaluation.deleted')->count());
    }

    public function test_admin_can_delete_from_the_selected_store(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs(User::factory()->admin()->create())->withSession([CurrentStore::SESSION_KEY => $evaluation->store_id])
            ->delete('/store/evaluations/'.$evaluation->id, ['return_to' => '/store'])->assertRedirect('/store');
        $this->assertModelMissing($evaluation);
        $this->assertDatabaseHas('activity_logs', ['action' => 'evaluation.deleted', 'subject_id' => $evaluation->id, 'acting_as_admin' => true]);
    }

    public function test_redirect_must_be_a_local_evaluation_list_or_dashboard(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($evaluation->store->owner);
        foreach (['https://example.com', '//example.com', '/store/evaluations/'.$evaluation->id, '/admin', '/store/../admin'] as $returnTo) {
            $this->delete('/store/evaluations/'.$evaluation->id, ['return_to' => $returnTo])->assertSessionHasErrors('return_to');
            $this->assertModelExists($evaluation);
        }
    }
}
