<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesigns;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Design\DesignService;
use App\Services\Design\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DesignRefinementTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Design $design;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Studio store']);
        Storage::disk('public')->put("designs/{$this->store->id}/a.png", 'png-bytes');
        $request = DesignRequest::query()->create(['store_id' => $this->store->id, 'type' => 'new_idea', 'prompt' => 'Abaya', 'category' => 'Abayas',
            'output_type' => 'product_photo', 'aspect_ratio' => '4:5', 'variants_count' => 1, 'status' => 'completed']);
        $this->design = Design::query()->create(['store_id' => $this->store->id, 'design_request_id' => $request->id, 'title' => 'Abaya · 1',
            'image_path' => "designs/{$this->store->id}/a.png", 'output_type' => 'product_photo', 'aspect_ratio' => '4:5', 'palette' => ['#000000'], 'status' => 'draft']);
    }

    public function test_an_edit_creates_a_new_version_from_a_copy_of_the_design(): void
    {
        $this->actingAs($this->store->owner)->post("/store/designs/{$this->design->id}/refine", [
            'presets' => ['clean_background', 'studio_light'], 'color' => 'gold', 'instruction' => 'Keep the sleeves plain', 'variants_count' => 2,
        ])->assertSessionHasNoErrors()->assertRedirect('/store/studio?since='.$this->design->design_request_id);

        $edit = DesignRequest::query()->where('type', DesignRequest::REFINEMENT)->sole();
        $this->assertSame($this->design->id, $edit->parent_design_id);
        $this->assertSame('4:5', $edit->aspect_ratio);
        $this->assertSame(2, $edit->variants_count);
        $this->assertSame('Abayas', $edit->category);
        $this->assertNotSame($this->design->image_path, $edit->source_image_path);
        Storage::disk('public')->assertExists($edit->source_image_path);
        $this->assertStringContainsString('seamless studio backdrop', $edit->prompt);
        $this->assertStringContainsString('Recolour the main body of the product to Gold', $edit->prompt);
        $this->assertStringContainsString('Merchant request, apply exactly: Keep the sleeves plain', $edit->prompt);
        Queue::assertPushed(GenerateDesigns::class, 1);

        $product = app(DesignService::class)->product($edit, null);
        $this->assertSame(['black'], $product['colors']);
        $prompt = app(PromptBuilder::class)->build($edit, $this->store, [], $product, true);
        $this->assertStringContainsString('Retouch the supplied image', $prompt);
        $this->assertStringContainsString('- Merchant request, apply exactly: Keep the sleeves plain', $prompt);
        $this->assertStringNotContainsString('Colour palette:', $prompt);

        $this->get('/store/studio')->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.type', 'refinement')
            ->where('requests.0.parent.id', $this->design->id)
            ->where('requests.0.parent.title', 'Abaya · 1'));
    }

    public function test_editing_opens_a_clean_workspace_without_earlier_finished_designs(): void
    {
        $owner = $this->store->owner;
        $running = DesignRequest::query()->create(['store_id' => $this->store->id, 'type' => 'new_idea', 'prompt' => 'Kaftan',
            'output_type' => 'product_photo', 'aspect_ratio' => '1:1', 'variants_count' => 1, 'status' => 'processing']);

        // The plain studio keeps the full history.
        $this->actingAs($owner)->get('/store/studio')->assertInertia(fn (Assert $page) => $page
            ->where('since', null)->has('requests', 2));

        // Opening a design to edit hides finished work; anything still generating stays.
        $this->get("/store/studio?refine={$this->design->id}")->assertInertia(fn (Assert $page) => $page
            ->where('since', $running->id)->has('requests', 1)->where('requests.0.id', $running->id));

        // The edit's own request shows after the submit, and polls keep the workspace.
        $this->post("/store/designs/{$this->design->id}/refine", ['instruction' => 'Shorten the sleeves', 'since' => $running->id])
            ->assertRedirect("/store/studio?since={$running->id}");
        $edit = DesignRequest::query()->where('type', DesignRequest::REFINEMENT)->sole();
        $edit->update(['status' => 'completed']);
        $running->update(['status' => 'completed']);
        $this->get("/store/studio?since={$running->id}")->assertInertia(fn (Assert $page) => $page
            ->has('requests', 1)->where('requests.0.id', $edit->id));
    }

    public function test_an_edit_needs_at_least_one_change_and_known_presets(): void
    {
        $this->actingAs($this->store->owner)->post("/store/designs/{$this->design->id}/refine", ['instruction' => '  '])
            ->assertSessionHasErrors('instruction');
        $this->post("/store/designs/{$this->design->id}/refine", ['presets' => ['make_it_pop']])->assertSessionHasErrors('presets.0');
        $this->post("/store/designs/{$this->design->id}/refine", ['presets' => ['clean_background', 'studio_light', 'sharpen_details', 'luxury_finish', 'lifestyle']])
            ->assertSessionHasErrors('presets');
        $this->assertSame(0, DesignRequest::query()->where('type', DesignRequest::REFINEMENT)->count());
        Queue::assertNothingPushed();
    }

    public function test_designs_of_other_stores_cannot_be_edited_or_opened(): void
    {
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);

        $this->actingAs($other->owner)->post("/store/designs/{$this->design->id}/refine", ['presets' => ['studio_light']])->assertNotFound();
        $this->get("/store/studio?refine={$this->design->id}")->assertInertia(fn (Assert $page) => $page->where('refine', null));
        $this->assertSame(0, DesignRequest::query()->where('type', DesignRequest::REFINEMENT)->count());
    }

    public function test_the_library_link_opens_the_editor_on_the_design(): void
    {
        $this->actingAs($this->store->owner)->get("/store/studio?refine={$this->design->id}")
            ->assertInertia(fn (Assert $page) => $page->where('refine.id', $this->design->id)->where('refine.aspect_ratio', '4:5'));
    }
}
