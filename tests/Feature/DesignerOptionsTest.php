<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\DesignerOption;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The merchant's own fabrics and sections in the AI designer's brief.
 */
class DesignerOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Lama']);
        $this->store->products()->create(['name' => 'فستان ناعم', 'external_id' => 'p1', 'category' => 'فساتين ناعمة']);
    }

    public function test_a_custom_fabric_and_section_are_added_and_offered_in_the_brief(): void
    {
        $owner = $this->store->owner;

        $this->actingAs($owner)->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => '  شيفون   مطرز بالترتر '])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/store/studio/designer/options', ['type' => 'section', 'label' => 'فساتين خطوبة', 'garment' => 'dress_evening'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('designer_options', ['store_id' => $this->store->id, 'type' => 'fabric', 'label' => 'شيفون مطرز بالترتر']);

        $this->get('/store/studio?mode=designer')->assertInertia(fn (Assert $page) => $page
            ->where('designer.custom_fabrics.0.label', 'شيفون مطرز بالترتر')
            // Behaves like chiffon for drape and sheerness.
            ->where('designer.custom_fabrics.0.base', 'chiffon')
            ->where('designer.custom_fabrics.0.key', fn ($key) => str_starts_with($key, 'custom:'))
            ->where('designer.categories.0.name', 'فساتين ناعمة')
            ->where('designer.categories.0.source', 'zid')
            ->where('designer.categories.1.name', 'فساتين خطوبة')
            ->where('designer.categories.1.garment', 'dress_evening')
            ->where('designer.categories.1.source', 'custom')
            ->where('designer.categories.1.id', fn ($id) => is_int($id)));
    }

    public function test_names_already_in_the_lists_are_refused(): void
    {
        $owner = $this->store->owner;

        // A built-in fabric, in Arabic or English.
        $this->actingAs($owner)->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => 'ساتان'])->assertSessionHasErrors('label');
        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => 'satin'])->assertSessionHasErrors('label');
        // A section synced from Zid.
        $this->post('/store/studio/designer/options', ['type' => 'section', 'label' => 'فساتين ناعمة', 'garment' => 'dress_daily'])->assertSessionHasErrors('label');
        // A section needs the garment it is designed as.
        $this->post('/store/studio/designer/options', ['type' => 'section', 'label' => 'بلايز'])->assertSessionHasErrors('garment');
        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => 'ك'])->assertSessionHasErrors('label');
        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => '123'])->assertSessionHasErrors('label');
        // The same name with diacritics, another alef or a taa marbuta is a duplicate.
        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => 'تويد مطرزة'])->assertSessionHasNoErrors();
        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => 'تُويد مطرزه'])->assertSessionHasErrors('label');

        $this->assertDatabaseCount('designer_options', 1);
    }

    public function test_another_store_cannot_remove_an_option(): void
    {
        $option = DesignerOption::query()->create(['store_id' => $this->store->id, 'type' => 'fabric', 'label' => 'تويد', 'normalized' => 'تويد']);
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);

        $this->actingAs($other->owner)->delete("/store/studio/designer/options/{$option->id}")->assertRedirect();
        $this->assertModelExists($option);
    }

    public function test_the_store_removes_its_own_option(): void
    {
        $option = DesignerOption::query()->create(['store_id' => $this->store->id, 'type' => 'fabric', 'label' => 'تويد', 'normalized' => 'تويد']);

        $this->actingAs($this->store->owner)->delete("/store/studio/designer/options/{$option->id}")->assertRedirect();
        $this->assertModelMissing($option);
    }
}
