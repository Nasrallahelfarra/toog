<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\DesignCollection;
use App\Models\DesignerOption;
use App\Models\Store;
use App\Models\User;
use App\Services\Designer\CollectionDesigner;
use App\Services\Designer\DesignerPrompt;
use App\Services\Market\PublicWebClient;
use App\Support\InlineImage;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * "Design like this": the competitor piece is reproduced in the merchant's
 * fabric. The planner and the image model see the piece (Image 1) and the
 * fabric (Image 2); modesty changes are the nearest allowed keys, listed.
 */
class DesignLikeThisTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private CompetitorProduct $piece;

    private string $png;

    /** @var list<array<string, mixed>> */
    private array $planner = [];

    /** @var list<array<string, mixed>> */
    private array $images = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Storage::fake('public');
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Lama']);
        $rival = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Sadeim', 'platform' => 'salla', 'url' => 'https://sadeim.example', 'is_active' => true]);
        $this->piece = CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $rival->id, 'external_id' => '555',
            'name' => 'فستان سهرة أوف شولدر', 'category' => 'فساتين سهرة', 'price' => 739, 'image_url' => 'https://cdn.sadeim.example/dress.jpg',
            'url' => 'https://sadeim.example/p/555', 'features' => ['colors' => ['dusty_pink']], 'first_seen_at' => now()->subDays(5), 'last_seen_at' => now()]);

        $image = imagecreatetruecolor(30, 40);
        ob_start();
        imagepng($image);
        $this->png = (string) ob_get_clean();
    }

    private function live(array $plan): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text', 'mojo.ai.gemini.image_model' => 'test-image']);
        Http::swap(new Factory);
        Http::fake(function ($request) use ($plan) {
            $parts = $request['contents'][0]['parts'];
            if (str_contains($request->url(), 'test-image:')) {
                $this->images[] = $parts;

                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png)]]]]]]]);
            }
            $system = $request['systemInstruction']['parts'][0]['text'] ?? '';
            if (str_contains($system, 'textile expert')) {
                $read = ['colors' => ['ivory'], 'pattern' => 'solid', 'print_scale' => 'medium', 'sheen' => 'high', 'opacity' => 'opaque',
                    'drape' => 'fluid', 'fabric_guess' => 'satin', 'confidence' => 0.9, 'motif_note_en' => ''];

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($read)]]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]]);
            }
            $this->planner[] = ['system' => $system, 'parts' => $parts, 'schema' => $request['generationConfig']['responseJsonSchema'] ?? []];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($plan)]]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]]);
        });
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function collection(array $brief = []): DesignCollection
    {
        Storage::disk('public')->put('design-sources/fabric.png', $this->png);

        return DesignCollection::query()->create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_id, 'status' => 'pending',
            'fabric_image_path' => 'design-sources/fabric.png', 'brief' => [
                'garment' => 'dress_evening', 'fabric' => 'satin', 'colors' => ['dusty_pink'], 'occasion' => 'evening', 'creativity' => 3, 'count' => 2,
                'render' => 'ghost', 'modesty' => 'strict', 'show_face' => false, 'notes' => null, 'tier' => null, 'age' => 'adult', 'category' => null,
                'inspiration' => ['kind' => 'competitor', 'id' => $this->piece->id, 'label' => $this->piece->name, 'image_url' => 'http://127.0.0.1/never.jpg',
                    'match' => 'close', 'evidence_id' => "C:{$this->piece->id}"],
                ...$brief,
            ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        $concept = fn (array $over) => [
            'role' => 'match', 'name_ar' => 'فستان Sadeim الوردي', 'name_en' => 'Sadeim rose gown', 'pitch_ar' => '', 'pitch_en' => '',
            'silhouette' => 'mermaid', 'neckline' => 'off_shoulder', 'sleeve' => 'sleeveless', 'length' => 'knee', 'closure' => 'back_zip',
            'placement' => 'neckline', 'pattern' => 'solid', 'style' => 'luxury', 'rationale_ar' => '', 'rationale_en' => '', 'risk_ar' => '', 'risk_en' => '',
            'finishing_ar' => '', 'finishing_en' => '', 'design_notes_en' => 'Draped off-shoulder band', 'variant_of_en' => '',
            'colors' => ['ivory'], 'embellishments' => ['crystals'], 'evidence_ids' => [], ...$over,
        ];

        return ['title_ar' => 'مثل القطعة', 'title_en' => 'Like the piece', 'story_ar' => '', 'story_en' => '', 'fabric_en' => '',
            'ref_garment' => 'dress_evening', 'ref_silhouette' => 'mermaid', 'ref_neckline' => 'off_shoulder', 'ref_sleeve' => 'sleeveless',
            'ref_length' => 'knee', 'ref_closure' => 'back_zip', 'ref_placement' => 'neckline', 'ref_embellishments' => ['crystals'],
            'ref_construction_en' => 'Fitted bodice with a draped off-shoulder band, flared hem from the knee.', 'ref_visible' => 'full', 'ref_is_garment' => true,
            'concepts' => [
                $concept([]),
                // A variant that tries two changes keeps only the first allowed one.
                $concept(['role' => 'match_variant', 'silhouette' => 'column', 'length' => 'ankle', 'sleeve' => 'straight', 'closure' => 'hidden_buttons', 'variant_of_en' => 'hidden buttons instead of the zip']),
            ]];
    }

    private function piecePicture(): void
    {
        $this->mock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('get')->once()
            ->withArgs(fn (string $url) => $url === 'https://cdn.sadeim.example/dress.jpg')
            ->andReturn(new Response(new Psr7Response(200, ['Content-Type' => 'image/png'], $this->png))));
    }

    public function test_design_like_this_prefills_a_close_copy(): void
    {
        $this->actingAs($this->store->owner)->get("/store/studio?mode=designer&inspire=product:{$this->piece->id}")
            ->assertInertia(fn (Assert $page) => $page->where('designerPrefill.inspiration.match', 'close')
                ->where('designerPrefill.notes', fn (string $notes) => str_contains($notes, 'صمّم مثل هذه القطعة') && ! str_contains($notes, 'عنصرين')));
    }

    public function test_the_piece_is_read_and_drawn_from_two_labelled_images(): void
    {
        $this->piecePicture();
        $this->live($this->plan());
        $collection = $this->collection();

        app(CollectionDesigner::class)->run($collection);
        $collection->refresh();

        // The piece is copied next to the collection; the browser's URL was never fetched.
        $this->assertNotNull($collection->reference_image_path);
        Storage::disk('public')->assertExists($collection->reference_image_path);
        $this->assertSame('completed', $collection->status, (string) $collection->error);

        // The planner saw the piece (Image 1) then the fabric (Image 2), in match mode.
        $planner = $this->planner[0];
        $this->assertStringContainsString('MATCH MODE', $planner['system']);
        $this->assertSame(DesignerPrompt::LABEL_DESIGN, $planner['parts'][0]['text']);
        $this->assertArrayHasKey('inlineData', $planner['parts'][1]);
        $this->assertSame(DesignerPrompt::LABEL_FABRIC, $planner['parts'][2]['text']);
        $this->assertArrayHasKey('inlineData', $planner['parts'][3]);
        $this->assertArrayHasKey('ref_construction_en', $planner['schema']['properties']);
        $this->assertStringNotContainsString('"enum"', json_encode($planner['schema']));

        // Strict modesty: only the nearest covered keys, each recorded.
        $first = $collection->concepts[0];
        $this->assertSame(['a_line', 'boat', 'straight', 'ankle'], [$first['silhouette'], $first['neckline'], $first['sleeve'], $first['length']]);
        $this->assertCount(4, $first['modesty_changes']);
        $this->assertSame(['crystals'], $first['embellishments']);
        // The competitor's name never ends up in the merchant's design.
        $this->assertStringNotContainsString('Sadeim', $first['name_en'].$first['name_ar']);
        $this->assertSame("C:{$this->piece->id}", $first['evidence'][0]['id']);

        // The variant keeps the cut and changes one thing only; the planner's
        // own modesty fix is not that change, and is still listed for the prompt.
        $second = $collection->concepts[1];
        $this->assertSame('a_line', $second['silhouette']);
        $this->assertSame('hidden_buttons', $second['closure']);
        $this->assertSame(['ankle', 'straight'], [$second['length'], $second['sleeve']]);
        $this->assertCount(4, $second['modesty_changes']);
        $this->assertStringContainsString('lengthen the hem to ankle length', end($this->images[1])['text']);

        // Each image call: Image 1 (the piece), Image 2 (the fabric), then the prompt.
        $parts = $this->images[0];
        $this->assertCount(2, array_filter($parts, fn (array $p) => isset($p['inlineData'])));
        $this->assertSame(DesignerPrompt::LABEL_DESIGN, $parts[0]['text']);
        $this->assertSame(DesignerPrompt::LABEL_FABRIC, $parts[2]['text']);
        $prompt = end($parts)['text'];
        $this->assertStringContainsString('NEW product photograph', $prompt);
        $this->assertStringContainsString('Remove every logo', $prompt);
        $this->assertStringContainsString('Required changes from Image 1', $prompt);
        $this->assertStringContainsString('add straight sleeves', $prompt);
        $this->assertStringContainsString('fabric of Image 2', $prompt);
        $this->assertStringContainsString('hidden buttons instead of the zip', end($this->images[1])['text']);
    }

    public function test_a_piece_without_a_picture_falls_back_to_inspiration(): void
    {
        $this->mock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('get')->andThrow(new RuntimeException('blocked')));
        $this->live($this->plan());
        $collection = $this->collection();

        app(CollectionDesigner::class)->run($collection);
        $collection->refresh();

        $this->assertSame('inspired', $collection->brief['inspiration']['match']);
        $this->assertTrue($collection->brief['reference_missing']);
        $this->assertNull($collection->reference_image_path);
        $this->assertStringNotContainsString('MATCH MODE', $this->planner[0]['system']);
        // Only the fabric, as before.
        $this->assertCount(1, array_filter($this->images[0], fn (array $p) => isset($p['inlineData'])));
    }

    public function test_a_picture_that_shows_no_garment_fails_the_collection(): void
    {
        $this->piecePicture();
        $this->live(['ref_is_garment' => false, ...array_diff_key($this->plan(), ['ref_is_garment' => true])]);
        $collection = $this->collection();

        app(CollectionDesigner::class)->run($collection);

        $this->assertSame('failed', $collection->fresh()->status);
        $this->assertSame(CollectionDesigner::NOT_A_GARMENT, $collection->fresh()->error);
        $this->assertSame([], $this->images);
    }

    public function test_an_unread_piece_is_designed_as_inspiration_not_copied(): void
    {
        $this->piecePicture();
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text', 'mojo.ai.gemini.image_model' => 'test-image']);
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'test-image:')) {
                $this->images[] = $request['contents'][0]['parts'];

                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png)]]]]]]]);
            }

            // The planner (and the fabric reading) fail.
            return Http::response(['error' => ['message' => 'unavailable']], 400);
        });
        $collection = $this->collection(['notes' => DesignerPrompt::matchNote('ar')]);

        app(CollectionDesigner::class)->run($collection);
        $collection->refresh();

        $this->assertSame('inspired', $collection->brief['inspiration']['match']);
        $this->assertTrue($collection->brief['reference_unread']);
        $this->assertNull($collection->brief['notes'], 'the copy note goes with the copy');
        foreach ($this->images as $parts) {
            $this->assertStringNotContainsString('Image 1', end($parts)['text']);
        }
    }

    public function test_an_inspired_brief_drops_the_copy_note_and_odd_input_is_refused(): void
    {
        Queue::fake();
        $owner = $this->store->owner;
        $brief = ['garment' => 'dress_evening', 'fabric' => 'satin', 'occasion' => 'evening', 'creativity' => 3, 'count' => 2, 'render' => 'ghost',
            'notes' => DesignerPrompt::matchNote('ar'),
            'inspiration' => ['kind' => 'competitor', 'id' => (string) $this->piece->id, 'label' => 'x', 'match' => 'inspired']];

        $this->actingAs($owner)->post('/store/studio/collections', $brief)->assertSessionHasNoErrors();
        $this->assertNull(DesignCollection::query()->sole()->brief['notes']);

        $this->post('/store/studio/designer/options', ['type' => 'fabric', 'label' => ['x' => 'تويد']])->assertSessionHasErrors('label');
    }

    public function test_a_picture_with_huge_dimensions_is_never_decoded(): void
    {
        // A tiny PNG whose header claims 30000 x 30000 pixels.
        $png = $this->png;
        $huge = substr($png, 0, 16).pack('N', 30000).pack('N', 30000).substr($png, 24);

        $this->assertNull(InlineImage::jpeg($huge));
        $this->assertNull(InlineImage::fromBytes($huge));
    }

    public function test_the_brief_keeps_the_copy_mode_and_the_store_fabric(): void
    {
        Queue::fake();
        $fabric = DesignerOption::query()->create(['store_id' => $this->store->id, 'type' => 'fabric', 'label' => 'شيفون مطرز بالترتر', 'normalized' => 'شيفون مطرز بالترتر']);
        $brief = ['garment' => 'dress_evening', 'occasion' => 'evening', 'creativity' => 3, 'count' => 2, 'render' => 'ghost',
            'inspiration' => ['kind' => 'competitor', 'id' => (string) $this->piece->id, 'label' => 'x', 'image_url' => 'http://127.0.0.1/x.jpg']];

        $this->actingAs($this->store->owner)->post('/store/studio/collections', [...$brief, 'fabric' => "custom:{$fabric->id}"])->assertSessionHasNoErrors();
        $this->post('/store/studio/collections', [...$brief, 'fabric' => 'custom:999999'])->assertSessionHasErrors('fabric');
        $this->post('/store/studio/collections', [...$brief, 'fabric' => 'satin', 'inspiration' => [...$brief['inspiration'], 'match' => 'nonsense']])->assertSessionHasErrors('inspiration.match');

        $saved = DesignCollection::query()->sole()->brief;
        $this->assertSame('close', $saved['inspiration']['match']);
        $this->assertSame('chiffon', $saved['fabric']);
        $this->assertSame('شيفون مطرز بالترتر', $saved['fabric_custom']);
        $this->assertFalse($saved['inspiration']['same_fabric']);
    }

    public function test_a_copy_in_the_original_fabric_sends_the_piece_alone_and_keeps_its_boldness(): void
    {
        $this->piecePicture();
        $plan = $this->plan();
        $this->live([...$plan, 'ref_colors' => ['emerald', 'gold', 'not-a-colour'], 'ref_pattern' => 'floral', 'ref_fabric' => 'satin']);
        $collection = $this->collection(['fabric' => null, 'colors' => [], 'modesty' => 'open',
            'inspiration' => ['kind' => 'competitor', 'id' => $this->piece->id, 'label' => $this->piece->name, 'image_url' => 'http://127.0.0.1/never.jpg',
                'match' => 'close', 'same_fabric' => true, 'evidence_id' => "C:{$this->piece->id}"]]);
        $collection->update(['fabric_image_path' => null]);

        app(CollectionDesigner::class)->run($collection);
        $collection->refresh();
        $this->assertSame('completed', $collection->status, (string) $collection->error);

        // The planner sees the piece alone and keeps its fabric.
        $planner = $this->planner[0];
        $this->assertStringContainsString(DesignerPrompt::PLANNER_SAME_FABRIC, $planner['system']);
        $this->assertStringNotContainsString(DesignerPrompt::PLANNER_OWN_FABRIC, $planner['system']);
        $this->assertCount(1, array_filter($planner['parts'], fn (array $p) => isset($p['inlineData'])));

        // As bold as the original: nothing changed for modesty.
        $first = $collection->concepts[0];
        $this->assertSame(['mermaid', 'off_shoulder', 'sleeveless', 'knee'], [$first['silhouette'], $first['neckline'], $first['sleeve'], $first['length']]);
        $this->assertSame([], $first['modesty_changes']);
        // Its own colours, print and fabric.
        $this->assertSame(['emerald', 'gold'], $first['colors']);
        $this->assertSame('floral', $first['pattern']);
        $this->assertSame('emerald', $collection->concepts[1]['colors'][0]);
        $this->assertSame('satin', $collection->brief['fabric']);
        $this->assertSame(['emerald', 'gold'], $collection->brief['colors']);

        // Each image call: the piece only, its own fabric, no required changes, no shayla.
        foreach ($this->images as $parts) {
            $this->assertCount(1, array_filter($parts, fn (array $p) => isset($p['inlineData'])));
            $prompt = end($parts)['text'];
            $this->assertStringContainsString(DesignerPrompt::SAME_FABRIC, $prompt);
            $this->assertStringNotContainsString('Image 2', $prompt);
            $this->assertStringNotContainsString('Required changes', $prompt);
            $this->assertStringNotContainsString('shayla', $prompt);
        }
    }

    public function test_the_original_fabric_needs_no_fabric_and_only_for_a_copy(): void
    {
        Queue::fake();
        $brief = ['garment' => 'dress_evening', 'occasion' => 'evening', 'creativity' => 3, 'count' => 2, 'render' => 'ghost', 'colors' => ['black'],
            'inspiration' => ['kind' => 'competitor', 'id' => (string) $this->piece->id, 'label' => 'x', 'match' => 'close', 'same_fabric' => '1']];

        // Inspiration only, or a copy in another fabric, still needs the fabric.
        $this->actingAs($this->store->owner)->post('/store/studio/collections', [...$brief, 'inspiration' => [...$brief['inspiration'], 'match' => 'inspired']])
            ->assertSessionHasErrors('fabric');
        $this->post('/store/studio/collections', [...$brief, 'inspiration' => [...$brief['inspiration'], 'same_fabric' => '0']])->assertSessionHasErrors('fabric');
        $this->assertSame(0, DesignCollection::query()->count());

        $this->post('/store/studio/collections', [...$brief, 'fabric' => 'satin'])->assertSessionHasNoErrors();
        $saved = DesignCollection::query()->sole();
        $this->assertTrue($saved->brief['inspiration']['same_fabric']);
        // The piece's own fabric replaces the brief's; the copy is as bold as the original.
        $this->assertNull($saved->brief['fabric']);
        $this->assertSame([], $saved->brief['colors']);
        $this->assertNull($saved->fabric_image_path);
        $this->assertSame('open', $saved->brief['modesty']);
    }
}
