<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Design;
use App\Models\DesignCollection;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The design library shows the AI designer's finished collections as
 * collections, and each one's designs on their own.
 */
class LibraryCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Storage::fake('public');
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Lama']);
    }

    private function collection(string $status, int $ready, ?string $title = 'ليالي الساتان'): DesignCollection
    {
        $collection = DesignCollection::query()->create([
            'store_id' => $this->store->id, 'status' => $status, 'title_ar' => $title, 'story_ar' => 'قصة المجموعة',
            'fabric_image_path' => 'design-sources/fabric.jpg',
            'brief' => ['garment' => 'dress_evening', 'fabric' => 'satin', 'occasion' => 'evening'],
        ]);
        $concepts = [];
        foreach (range(1, $ready) as $i) {
            $design = Design::query()->create(['store_id' => $this->store->id, 'design_collection_id' => $collection->id, 'title' => "تصميم {$i}",
                'image_path' => "designs/c{$collection->id}-{$i}.png", 'status' => $i === 1 ? 'approved' : 'draft', 'score' => 70 + $i * 2]);
            $concepts[] = ['status' => 'ready', 'name_ar' => "تصميم {$i}", 'image_path' => $design->image_path, 'design_id' => $design->id];
        }
        $concepts[] = ['status' => 'failed', 'name_ar' => 'لم يكتمل'];
        $collection->update(['concepts' => $concepts]);

        return $collection;
    }

    public function test_finished_collections_are_listed_as_collections(): void
    {
        $done = $this->collection('completed', 3);
        $this->collection('processing', 1, 'قيد التصميم');
        // A loose design from another studio mode stays out of the collections.
        Design::query()->create(['store_id' => $this->store->id, 'title' => 'Mockup', 'image_path' => 'designs/m.png']);

        $this->actingAs($this->store->owner)->get('/store/library?view=collections')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/library/index')
                ->where('view', 'collections')
                ->where('designs', null)
                ->where('counts.collections', 1)
                ->has('collections.data', 1)
                ->where('collections.data.0.id', $done->id)
                ->where('collections.data.0.title', 'ليالي الساتان')
                ->where('collections.data.0.ready', 3)
                ->where('collections.data.0.total', 4)
                ->where('collections.data.0.approved', 1)
                ->where('collections.data.0.score', 74)
                ->where('collections.data.0.garment', 'فستان سهرة')
                ->has('collections.data.0.images', 3)
                ->where('collections.data.0.fabric_image_url', fn ($url) => str_contains($url, 'fabric.jpg'))
                ->where('collections.data.0.open_href', "/store/studio?mode=designer&collection={$done->id}"));
    }

    public function test_one_collections_designs_open_on_their_own(): void
    {
        $done = $this->collection('completed', 2);
        $this->collection('completed', 3, 'مجموعة أخرى');

        $this->actingAs($this->store->owner)->get("/store/library?collection={$done->id}")
            ->assertInertia(fn (Assert $page) => $page->where('view', 'designs')
                ->where('collection', ['id' => $done->id, 'title' => 'ليالي الساتان'])
                ->has('designs.data', 2)
                ->where('collections', null));
    }

    public function test_another_stores_collection_is_not_shown(): void
    {
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $theirs = DesignCollection::query()->create(['store_id' => $other->id, 'status' => 'completed', 'brief' => ['garment' => 'abaya']]);

        $this->actingAs($this->store->owner)->get("/store/library?collection={$theirs->id}")
            ->assertInertia(fn (Assert $page) => $page->where('collection', null));
        $this->get('/store/library?view=collections')
            ->assertInertia(fn (Assert $page) => $page->has('collections.data', 0));
    }
}
