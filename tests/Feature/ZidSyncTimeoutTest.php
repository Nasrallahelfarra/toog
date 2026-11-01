<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\SyncStoreData;
use App\Models\Product;
use App\Models\User;
use App\Services\Zid\ZidSyncState;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZidSyncTimeoutTest extends TestCase
{
    public function test_timeout_rolls_back_interrupted_catalog_transaction_before_recording_failure(): void
    {
        // A separate in-memory DB without RefreshDatabase's outer transaction
        // lets us simulate a worker signal during a real catalog transaction.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh')->assertSuccessful();
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Timeout test']);
        $state = app(ZidSyncState::class);
        [$run] = $state->reserve($store);
        $state->claim($run->id, $store);
        $job = unserialize(serialize(new SyncStoreData($store, runId: $run->id)));

        DB::beginTransaction();
        Product::create(['store_id' => $store->id, 'name' => 'Uncommitted product']);
        $job->failed(new TimeoutExceededException('Test timeout'));

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('products', 0);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('timed_out', $run->fresh()->error);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
