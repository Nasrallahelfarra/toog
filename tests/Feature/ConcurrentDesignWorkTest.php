<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\GenerateDesignCollection;
use App\Jobs\GenerateDesigns;
use App\Models\DesignCollection;
use App\Models\DesignRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * Several people design at once. Asking for a design never waits on
 * anyone else's: each request gets its own job, and with more than one
 * queue worker those jobs run side by side. The queue settings keep that
 * safe: no job may run longer than the window after which another worker
 * would take it.
 */
class ConcurrentDesignWorkTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Storage::fake('public');
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Abaya']);
    }

    private function designer(string $name): User
    {
        $member = User::factory()->create(['name' => $name]);
        $this->store->members()->attach($member->id, ['role' => Store::ROLE_STAFF, 'permissions' => json_encode(['studio'])]);

        return $member;
    }

    public function test_two_staff_members_ask_for_designs_while_each_others_are_still_in_progress(): void
    {
        Queue::fake();
        $sara = $this->designer('Sara');
        $noor = $this->designer('Noor');

        $brief = ['garment' => 'abaya', 'fabric' => 'korean_crepe', 'occasion' => 'eid', 'creativity' => 3, 'count' => 2, 'render' => 'on_model'];
        $idea = ['type' => 'new_idea', 'prompt' => 'Black abaya with gold piping', 'output_type' => 'product_photo', 'aspect_ratio' => '4:5', 'variants_count' => 2];

        // Sara's collection is queued and not finished; Noor is not held back.
        $this->actingAs($sara)->post('/store/studio/collections', $brief)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($noor)->post('/store/studio/collections', [...$brief, 'garment' => 'dress_evening', 'fabric' => 'satin'])
            ->assertSessionHasNoErrors()->assertRedirect();
        // The same holds for single designs, and for one person asking twice.
        $this->actingAs($noor)->post('/store/studio', $idea)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($sara)->post('/store/studio', $idea)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($sara)->post('/store/studio/collections', [...$brief, 'count' => 1])->assertSessionHasNoErrors()->assertRedirect();

        $collections = DesignCollection::query()->orderBy('id')->get();
        $requests = DesignRequest::query()->orderBy('id')->get();

        $this->assertSame([$sara->id, $noor->id, $sara->id], $collections->pluck('user_id')->all());
        $this->assertSame([$noor->id, $sara->id], $requests->pluck('user_id')->all());
        $this->assertSame(['pending'], $collections->pluck('status')->unique()->all());
        $this->assertSame(['pending'], $requests->pluck('status')->unique()->all());

        // One job per request, so each can be picked up by its own worker.
        Queue::assertPushed(GenerateDesignCollection::class, 3);
        Queue::assertPushed(GenerateDesigns::class, 2);
        foreach ($collections as $collection) {
            Queue::assertPushed(GenerateDesignCollection::class, fn ($job) => $job->collectionId === $collection->id);
        }

        // Each of them follows their own request from the studio while it runs.
        $this->actingAs($noor)->get('/store/studio?mode=designer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('collections', 3)
                ->has('requests', 1)->where('requests.0.id', $requests[0]->id));
    }

    public function test_the_studio_keeps_only_a_members_latest_request_and_what_is_still_generating(): void
    {
        $sara = $this->designer('Sara');
        $noor = $this->designer('Noor');
        $request = fn (User $by, string $status) => DesignRequest::query()->create(['store_id' => $this->store->id, 'user_id' => $by->id,
            'type' => 'new_idea', 'prompt' => 'Abaya', 'output_type' => 'product_photo', 'aspect_ratio' => '4:5', 'variants_count' => 1, 'status' => $status]);

        $old = $request($sara, 'completed');
        $stillRunning = $request($sara, 'processing');
        $latest = $request($sara, 'completed');
        $noors = $request($noor, 'completed');

        // Sara: her latest and the one still generating, newest first. Not
        // her earlier finished request, and nothing of Noor's.
        $this->actingAs($sara)->get('/store/studio?mode=idea')
            ->assertInertia(fn (Assert $page) => $page
                ->has('requests', 2)
                ->where('requests.0.id', $latest->id)
                ->where('requests.1.id', $stillRunning->id));

        // Once it finishes, only the latest remains.
        $stillRunning->update(['status' => 'completed']);
        $this->get('/store/studio?mode=idea')
            ->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.id', $latest->id));

        $this->actingAs($noor)->get('/store/studio?mode=idea')
            ->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.id', $noors->id));

        // Someone who has not asked for anything sees an empty bench.
        $this->actingAs($this->store->owner)->get('/store/studio?mode=idea')
            ->assertInertia(fn (Assert $page) => $page->has('requests', 0));
        $this->assertNotNull($old->fresh());
    }

    public function test_display_shots_are_asked_for_from_the_free_idea_tab(): void
    {
        Queue::fake();
        $sara = $this->designer('Sara');

        // The separate "product shots" tab is gone; an old link to it opens
        // the free-idea tab, which offers the same output types.
        $this->actingAs($sara)->get('/store/studio?mode=mockup')
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'idea')
                ->where('outputTypes', DesignRequest::OUTPUT_TYPES));

        $this->post('/store/studio', ['type' => 'new_idea', 'prompt' => 'Black abaya on a model', 'output_type' => 'mockup_model', 'aspect_ratio' => '4:5', 'variants_count' => 1])
            ->assertSessionHasNoErrors()->assertRedirect('/store/studio?mode=idea');
        $this->assertSame('mockup_model', DesignRequest::query()->sole()->output_type);

        // A request saved from the old tab still retries, back on this tab.
        $old = DesignRequest::query()->create(['store_id' => $this->store->id, 'user_id' => $sara->id, 'type' => 'mockup', 'prompt' => 'Lifestyle shot',
            'output_type' => 'lifestyle', 'aspect_ratio' => '1:1', 'variants_count' => 1, 'status' => 'failed']);
        $this->from('/store/studio?mode=mockup')->post("/store/studio/{$old->id}/retry")->assertRedirect('/store/studio?mode=idea');
        $this->assertSame('pending', $old->fresh()->status);
    }

    public function test_no_job_runs_longer_than_the_window_after_which_another_worker_would_take_it(): void
    {
        $longest = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            if ($timeout === null) {
                continue;
            }

            $longest = max($longest, $timeout);

            foreach (['database', 'redis', 'beanstalkd'] as $connection) {
                $this->assertLessThan(
                    config("queue.connections.{$connection}.retry_after"),
                    $timeout,
                    "{$class} may run {$timeout}s, longer than the {$connection} queue's retry_after: a second worker would take it mid-run.",
                );
            }
        }

        // The design collection is the long one this guards.
        $this->assertSame((new GenerateDesignCollection(1))->timeout, $longest);
    }
}
