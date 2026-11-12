<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesignCollection;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Design;
use App\Models\DesignCollection;
use App\Models\DesignerBriefing;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Designer\CollectionDesigner;
use App\Services\Designer\DesignerInsights;
use App\Support\FashionVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The AI fashion designer inside the studio: store intelligence (units
 * only, never money), collections planned and drawn concept by concept,
 * and grounded advice.
 */
class AiDesignerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $best;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Storage::fake('public');
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Abaya']);
        $last = now()->startOfMonth()->subMonth();

        // A best seller rising over the last three months, and slower products.
        foreach ([['Black crepe abaya', 'black', 'solid', [6, 8, 10, 20, 25, 30], 4], ['Beige abaya', 'beige', 'embroidery', [5, 5, 5, 6, 6, 6], 40],
            ['Navy abaya', 'navy', 'solid', [3, 3, 3, 2, 2, 2], 60], ['Grey abaya', 'grey', 'solid', [2, 2, 2, 1, 1, 1], 30],
            ['Olive abaya', 'olive', 'geometric', [2, 1, 2, 1, 1, 0], 20]] as [$name, $color, $pattern, $units, $stock]) {
            $product = $this->store->products()->create(['name' => $name, 'external_id' => $name, 'category' => 'عبايات', 'price' => 250,
                'revenue' => 99999, 'stock' => $stock, 'features' => ['colors' => [$color], 'pattern' => $pattern, 'material' => 'crepe', 'style' => 'modern']]);
            foreach ($units as $i => $n) {
                ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id,
                    'period' => $last->copy()->subMonths(5 - $i), 'orders' => $n, 'units' => $n, 'revenue' => $n * 250]);
            }
            $this->best ??= $product;
        }

        $a = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Rival A', 'platform' => 'website', 'is_active' => true]);
        $b = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Rival B', 'platform' => 'website', 'is_active' => true]);
        foreach ([[$a, 'Sage abaya', true], [$a, 'Sage kimono', false], [$b, 'Sage cape abaya', true], [$b, 'Sage wrap abaya', false], [$b, 'Ivory abaya', false]] as $i => [$competitor, $name, $best]) {
            CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $competitor->id, 'external_id' => "cp{$i}", 'name' => $name,
                'category' => 'Abayas', 'price' => 300, 'is_best_seller' => $best, 'features' => ['colors' => [str_starts_with($name, 'Ivory') ? 'ivory' : 'sage'], 'pattern' => 'solid'],
                'first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDay()]);
        }
    }

    public function test_the_studio_opens_on_the_ai_designer_with_its_vocabulary(): void
    {
        $this->actingAs($this->store->owner)->get('/store/studio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/studio/index')
                ->where('mode', 'designer')
                ->has('designer.vocabulary.garments.abaya')
                ->has('designer.vocabulary.fabrics.nada')
                ->has('designer.vocabulary.silhouettes.abaya.butterfly')
                ->has('collections', 0)
                ->missing('insights'));

        // Older links keep their mode.
        $this->get('/store/studio?prompt=Floral+abaya')->assertInertia(fn (Assert $page) => $page->where('mode', 'idea'));
    }

    public function test_insights_read_units_and_competitor_signals_without_money(): void
    {
        $insights = app(DesignerInsights::class)->build($this->store, 'en');

        $this->assertSame('Black crepe abaya', $insights['bestsellers'][0]['name']);
        $this->assertSame(75, $insights['bestsellers'][0]['units']);
        $this->assertSame('abaya', $insights['bestsellers'][0]['garment']);
        $this->assertSame('Black crepe abaya', $insights['rising'][0]['name']);
        $this->assertContains('best_seller_label', $insights['competitor_hits'][0]['signals']);
        // Sage is in most competitor products and absent from the store.
        $gap = collect($insights['gaps'])->firstWhere('value', 'sage');
        $this->assertNotNull($gap);
        $this->assertSame(0, $gap['mine_pct']);
        $this->assertSame(['sage'], $gap['prefill']['colors']);

        $json = json_encode($insights);
        $this->assertStringNotContainsString('revenue', $json);
        $this->assertStringNotContainsString('99999', $json);
    }

    public function test_a_brief_becomes_a_collection_drawn_concept_by_concept_in_demo_mode(): void
    {
        Queue::fake();
        $owner = $this->store->owner;

        $this->actingAs($owner)->post('/store/studio/collections', [
            'garment' => 'abaya', 'fabric' => 'korean_crepe', 'fabric_image' => UploadedFile::fake()->image('swatch.jpg', 60, 60),
            'colors' => ['black', 'gold'], 'occasion' => 'eid', 'creativity' => 3, 'count' => 4, 'render' => 'on_model',
            'inspiration' => ['kind' => 'bestseller', 'id' => (string) $this->best->id, 'label' => 'Black crepe abaya'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $collection = DesignCollection::query()->sole();
        $this->assertSame('pending', $collection->status);
        $this->assertSame('strict', $collection->brief['modesty']);
        $this->assertSame("P:{$this->best->id}", $collection->brief['inspiration']['evidence_id']);
        Storage::disk('public')->assertExists($collection->fabric_image_path);
        Queue::assertPushed(GenerateDesignCollection::class, fn ($job) => $job->collectionId === $collection->id && $job->only === null);

        app(CollectionDesigner::class)->run($collection);
        $collection->refresh();

        $this->assertSame('completed', $collection->status);
        $this->assertNotEmpty($collection->title_ar);
        $this->assertSame(['safe_bet', 'trend_twist', 'competitor_gap', 'signature'], array_column($collection->concepts, 'role'));
        $silhouettes = array_column($collection->concepts, 'silhouette');
        $this->assertCount(4, array_unique($silhouettes), 'concepts differ in cut');

        foreach ($collection->concepts as $concept) {
            $this->assertSame('ready', $concept['status']);
            $this->assertContains($concept['silhouette'], array_keys(FashionVocabulary::silhouettes()['abaya']));
            $this->assertGreaterThan(0, $concept['production']['meters_min']);
            $this->assertNotNull($concept['score']);
            $design = Design::query()->findOrFail($concept['design_id']);
            $this->assertSame($collection->id, $design->design_collection_id);
            $this->assertContains('designer', $design->tags);
            $this->assertStringContainsString('FABRIC SWATCH', $design->prompt);
            $this->assertStringContainsString('face is out of frame', $design->prompt);
        }
        // The merchant's inspiration is the first concept's evidence.
        $this->assertSame("P:{$this->best->id}", $collection->concepts[0]['evidence'][0]['id']);
        $this->assertSame('own_sales', $collection->concepts[0]['source']);

        $this->get("/store/studio?collection={$collection->id}")
            ->assertInertia(fn (Assert $page) => $page->where('collections.0.id', $collection->id)
                ->where('collections.0.concepts.0.status', 'ready')
                ->where('openCollection', $collection->id));
    }

    public function test_one_concept_can_be_redrawn_and_replaces_its_library_design(): void
    {
        Queue::fake();
        $collection = $this->collection();
        $before = $collection->concepts[1]['design_id'];

        $this->actingAs($this->store->owner)->post("/store/studio/collections/{$collection->id}/concepts/1/redraw")->assertRedirect();
        Queue::assertPushed(GenerateDesignCollection::class, fn ($job) => $job->only === 1);

        app(CollectionDesigner::class)->run($collection->fresh(), 1);
        $after = $collection->fresh();

        $this->assertSame('completed', $after->status);
        $this->assertNotSame($before, $after->concepts[1]['design_id']);
        $this->assertNull(Design::query()->find($before));
        $this->assertSame($collection->concepts[0]['design_id'], $after->concepts[0]['design_id']);
    }

    public function test_the_model_plan_is_cleaned_to_allowed_keys_and_known_evidence(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text', 'mojo.ai.gemini.image_model' => 'test-image']);
        $image = imagecreatetruecolor(30, 40);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        $concept = fn (string $role, string $silhouette) => [
            'role' => $role, 'name_ar' => 'عباية فراشة', 'name_en' => 'Butterfly abaya', 'pitch_ar' => 'قصة مريحة', 'pitch_en' => 'Easy cut',
            'silhouette' => $silhouette, 'neckline' => 'v_shallow', 'sleeve' => 'laser_sleeve', 'length' => 'midi', 'closure' => 'open_front',
            'placement' => 'cuffs', 'pattern' => 'calligraphy', 'style' => 'modern', 'rationale_ar' => 'يبيع', 'rationale_en' => 'Sells',
            'risk_ar' => '', 'risk_en' => '', 'finishing_ar' => '', 'finishing_en' => '', 'design_notes_en' => 'Soft drape',
            'colors' => ['black', 'neon_purple'], 'embellishments' => ['crystals', 'diamonds'], 'evidence_ids' => ["P:{$this->best->id}", 'P:999999'],
        ];
        $plan = ['title_ar' => 'مجموعة العيد', 'title_en' => 'Eid edit', 'story_ar' => 'قصة', 'story_en' => 'Story',
            'concepts' => [$concept('safe_bet', 'butterfly'), $concept('trend_twist', 'butterfly')]];

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($plan, $png) {
            if (str_contains($request->url(), 'test-image:')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]]]]]);
            }
            // Gemini caps schema complexity: the concept items stay flat, with no enums.
            $schema = $request['generationConfig']['responseJsonSchema'];
            $this->assertStringNotContainsString('"enum"', json_encode($schema));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($plan)]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
        });

        $collection = DesignCollection::query()->create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_id, 'status' => 'pending', 'brief' => [
            'garment' => 'abaya', 'fabric' => 'chiffon', 'colors' => ['black'], 'occasion' => 'daily', 'creativity' => 1, 'count' => 2,
            'render' => 'ghost', 'modesty' => 'strict', 'notes' => null, 'inspiration' => null, 'tier' => null, 'age' => 'adult',
        ]]);
        app(CollectionDesigner::class)->run($collection);
        $concepts = $collection->fresh()->concepts;

        $this->assertTrue($collection->fresh()->from_model);
        $first = $concepts[0];
        $this->assertSame('butterfly', $first['silhouette']);
        $this->assertNotSame('butterfly', $concepts[1]['silhouette'], 'a repeated silhouette is moved to an unused one');
        $this->assertSame('round', $first['neckline'], 'strict modesty turns a V neck round');
        $this->assertSame('floor', $first['length'], 'strict modesty keeps the full length');
        $this->assertSame('straight', $first['sleeve'], 'an unknown sleeve falls back to the default');
        $this->assertSame('solid', $first['pattern'], 'calligraphy is not allowed on garments');
        $this->assertSame(['black'], $first['colors']);
        $this->assertSame(['crystals'], $first['embellishments']);
        $this->assertSame('full', $first['lining'], 'sheer chiffon is fully lined');
        $this->assertSame(["P:{$this->best->id}"], array_column($first['evidence'], 'id'), 'unknown evidence ids are dropped');
        $this->assertStringContainsString('75', $first['evidence'][0]['claim_en'], 'evidence numbers come from the database');
    }

    public function test_advice_is_grounded_and_falls_back_to_templates_in_demo_mode(): void
    {
        $this->actingAs($this->store->owner)->post('/store/studio/designer/advice')->assertRedirect();

        $briefing = DesignerBriefing::query()->sole();
        $this->assertFalse($briefing->from_model);
        $this->assertNotEmpty($briefing->advice['items']);
        $this->assertSame("P:{$this->best->id}", $briefing->advice['items'][0]['refs'][0]);
        $this->assertStringNotContainsString('99999', json_encode($briefing->advice));

        $this->get('/store/studio')->assertInertia(fn (Assert $page) => $page->has('designer.briefing.items'));
    }

    public function test_collections_are_private_to_their_store(): void
    {
        $collection = $this->collection();
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);

        $this->actingAs($other->owner)->post("/store/studio/collections/{$collection->id}/retry")->assertNotFound();
        $this->actingAs($other->owner)->delete("/store/studio/collections/{$collection->id}")->assertNotFound();
        $this->assertNotNull($collection->fresh());
    }

    public function test_a_brief_needs_a_fabric_photo_or_type(): void
    {
        $this->actingAs($this->store->owner)->post('/store/studio/collections', [
            'garment' => 'dress_daily', 'occasion' => 'daily', 'creativity' => 3, 'count' => 2, 'render' => 'ghost',
        ])->assertSessionHasErrors('fabric');
    }

    private function collection(): DesignCollection
    {
        $collection = DesignCollection::query()->create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_id, 'status' => 'pending', 'brief' => [
            'garment' => 'dress_evening', 'fabric' => 'satin', 'colors' => ['emerald'], 'occasion' => 'wedding_guest', 'creativity' => 4, 'count' => 2,
            'render' => 'sketch', 'modesty' => 'standard', 'notes' => null, 'inspiration' => null, 'tier' => 'premium', 'age' => 'adult',
        ]]);
        app(CollectionDesigner::class)->run($collection);

        return $collection->fresh();
    }
}
