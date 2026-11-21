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

        // Each of them follows the work from the studio while it runs.
        $this->actingAs($noor)->get('/store/studio?mode=designer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('collections', 3)->has('requests', 2));
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
