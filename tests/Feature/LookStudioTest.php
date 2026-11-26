<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesigns;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\DesignRequestImage;
use App\Models\Store;
use App\Models\User;
use App\Services\Design\DesignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The free idea as a look: the merchant's hand sketches and fabric photos,
 * each with a role, made into photographs of the garment in that fabric.
 */
class LookStudioTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Store']);
    }

    private function png(int $width = 40, int $height = 60): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{id: int, role: string}
     */
    private function upload(string $role, ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->owner)->post('/store/studio/uploads', [
            'file' => UploadedFile::fake()->createWithContent('photo.png', $this->png()),
            'role' => $role,
        ], ['Accept' => 'application/json'])->assertOk();

        return ['id' => $response->json('id'), 'role' => $role];
    }

    /**
     * @param  list<array{id: int, role: string}>  $images
     * @return array<string, mixed>
     */
    private function look(array $images, array $overrides = []): array
    {
        return [
            'type' => 'look', 'prompt' => 'ابغى هذا الموديل على هذه الخامة وتلبسه العارضة', 'images' => $images,
            'presentation' => ['mode' => 'model', 'face' => 'cropped', 'background' => 'studio'],
            'back_view' => true, 'aspect_ratio' => '3:4', 'variants_count' => 2, ...$overrides,
        ];
    }

    public function test_an_upload_is_kept_as_an_upright_jpeg_no_larger_than_2048(): void
    {
        $response = $this->actingAs($this->owner)->post('/store/studio/uploads', [
            'file' => UploadedFile::fake()->createWithContent('big.png', $this->png(3000, 1500)),
            'role' => 'fabric',
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['role' => 'fabric', 'width' => 2048, 'height' => 1024]);

        $image = DesignRequestImage::query()->findOrFail($response->json('id'));
        $this->assertNull($image->design_request_id, 'staged until the request is sent');
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($image->path))['mime']);

        // Not a picture, or an unknown role.
        $this->actingAs($this->owner)->post('/store/studio/uploads', ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'role' => 'fabric'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->owner)->post('/store/studio/uploads', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->png()), 'role' => 'selfie'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_a_look_attaches_the_pictures_in_role_order_and_is_queued(): void
    {
        $fabric = $this->upload('fabric');
        $front = $this->upload('sketch_front');
        $back = $this->upload('sketch_back');

        $this->actingAs($this->owner)->post('/store/studio', $this->look([$fabric, $front, $back]))->assertSessionHasNoErrors();

        $request = DesignRequest::query()->sole();
        $this->assertSame(DesignRequest::LOOK, $request->type);
        $this->assertSame('mockup_model', $request->output_type);
        $this->assertSame(['sketch_front', 'sketch_back', 'fabric'], $request->images->pluck('role')->all());
        $this->assertTrue($request->presentation['back_view']);
        $this->assertSame(4, $request->slots(), 'two variants, each from the front and from behind');
        Queue::assertPushed(GenerateDesigns::class);

        $this->actingAs($this->owner)->get('/store/studio')
            ->assertInertia(fn (Assert $page) => $page
                ->where('look.limits.total', 6)
                ->where('requests.0.type', 'look')
                ->where('requests.0.slots', 4)
                ->has('requests.0.images', 3));
    }

    public function test_only_ones_own_unsent_pictures_within_the_limits_can_be_sent(): void
    {
        $colleague = User::factory()->create();
        $this->store->members()->attach($colleague->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode(['studio'])]);
        $theirs = $this->upload('fabric', $colleague);

        $this->actingAs($this->owner)->post('/store/studio', $this->look([$theirs]))->assertSessionHasErrors('images');

        $two = [$this->upload('sketch_front'), $this->upload('sketch_front')];
        $this->actingAs($this->owner)->post('/store/studio', $this->look($two))->assertSessionHasErrors('images');

        // Without a back sketch there is no back view.
        $this->actingAs($this->owner)->post('/store/studio', $this->look([$this->upload('fabric')]))->assertSessionHasNoErrors();
        $this->assertFalse(DesignRequest::query()->latest('id')->first()->presentation['back_view']);
    }

    public function test_the_pictures_reach_the_model_labelled_by_role_and_a_retry_makes_only_what_is_missing(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text', 'mojo.ai.gemini.image_model' => 'test-image']);
        $this->actingAs($this->owner)->post('/store/studio', $this->look([$this->upload('sketch_front'), $this->upload('fabric')], ['variants_count' => 2, 'back_view' => false]));
        $request = DesignRequest::query()->sole();

        $png = $this->png();
        $failTurned = true;
        $labels = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($http) use ($png, &$failTurned, &$labels) {
            if (str_contains($http->url(), 'test-image:')) {
                $texts = collect($http['contents'][0]['parts'])->pluck('text')->filter()->values()->all();
                $labels[] = $texts;
                // The second image (the three-quarter pose) fails on the first run, however often it is tried.
                if ($failTurned && str_contains((string) end($texts), 'three-quarter')) {
                    return Http::response(['error' => ['message' => 'bad request']], 400);
                }

                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]]]]]);
            }
            $plan = ['garment_en' => 'long fitted evening dress', 'construction_en' => 'Fitted to the knee, balloon sleeves.', 'back_en' => '', 'fabric_en' => 'Sequinned marbled lace.',
                'intent_en' => 'This sketch in this fabric on a model.', 'modesty_changes' => [], 'colors' => ['beige', 'nonsense'], 'pattern' => 'abstract', 'material' => 'lace', 'style' => 'luxury'];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($plan)]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
        });

        app(DesignService::class)->generate($request);
        $request->refresh();

        $this->assertSame('completed', $request->status, 'one image made is enough to complete');
        $this->assertCount(1, $request->failures);
        $this->assertSame(1, Design::query()->where('design_request_id', $request->id)->count());
        $this->assertSame(['beige'], $request->plan['colors'], 'unknown keys are dropped');
        $this->assertStringStartsWith('FRONT SKETCH', $labels[0][0]);
        $this->assertStringStartsWith('FABRIC SWATCH 1 of 1', $labels[0][1]);
        $this->assertStringContainsString('Create a NEW photorealistic', end($labels[0]));

        // The retry makes only the missing image, and does not read the pictures again.
        $failTurned = false;
        app(DesignService::class)->generate($request);
        $request->refresh();
        $this->assertSame(2, Design::query()->where('design_request_id', $request->id)->count());
        $this->assertNull($request->failures);
        $this->assertSame([0, 1], Design::query()->where('design_request_id', $request->id)->orderBy('variant')->pluck('variant')->all());
    }

    public function test_refining_a_look_keeps_its_fabric_pictures(): void
    {
        $this->actingAs($this->owner)->post('/store/studio', $this->look([$this->upload('sketch_front'), $this->upload('fabric')], ['back_view' => false]));
        $request = DesignRequest::query()->sole();
        Storage::disk('public')->put('designs/look.png', $this->png());
        $design = Design::query()->create(['store_id' => $this->store->id, 'design_request_id' => $request->id, 'user_id' => $this->owner->id,
            'title' => 'Look · 1', 'image_path' => 'designs/look.png', 'output_type' => 'mockup_model', 'aspect_ratio' => '3:4', 'status' => 'draft', 'variant' => 0, 'view' => 'front']);

        $this->actingAs($this->owner)->post("/store/designs/{$design->id}/refine", ['instruction' => 'أطول بالكم'])->assertSessionHasNoErrors();

        $edit = DesignRequest::query()->where('type', DesignRequest::REFINEMENT)->sole();
        $this->assertSame(['fabric'], $edit->images->pluck('role')->all());
    }

    public function test_an_over_budget_store_cannot_send_a_look(): void
    {
        $this->store->forceFill(['ai_monthly_budget' => 0.01])->save();
        \App\Models\AiUsage::query()->create(['store_id' => $this->store->id, 'operation' => 'design', 'provider' => 'gemini', 'model' => 'x', 'cost' => 5, 'images' => 1]);

        $this->actingAs($this->owner)->post('/store/studio', $this->look([]))->assertSessionHasErrors('budget');
    }
}
