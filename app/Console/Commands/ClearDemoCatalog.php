<?php

namespace App\Console\Commands;

use App\Jobs\SyncStoreData;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClearDemoCatalog extends Command
{
    protected $signature = 'demo:clear-catalog
        {email : Owner email used by DemoStoresSeeder}
        {--zid-store= : Expected connected Zid store ID}
        {--apply : Delete the previewed fixture catalog; without this option nothing changes}';

    protected $description = 'Preview or remove seeded catalog products and their sales from one connected demo store';

    // Original catalog sizes in DemoStoresSeeder, before its single duplicate.
    private const CATALOG_SIZES = [
        'lama@demo.sa' => 42,
        'hadaya@demo.sa' => 35,
        'najd@demo.sa' => 14,
    ];

    public function handle(PerformanceClassifier $classifier): int
    {
        $email = (string) $this->argument('email');
        $zidStore = (string) $this->option('zid-store');

        if (! isset(self::CATALOG_SIZES[$email]) || ! ctype_digit($zidStore) || (int) $zidStore < 1) {
            $this->error('Supply a seeded owner email and the expected --zid-store ID. Nothing changed.');

            return self::FAILURE;
        }

        $stores = Store::query()->whereHas('owner', fn ($q) => $q->where('email', $email))
            ->where('platform', 'zid')->where('platform_store_id', $zidStore)->get();

        if ($stores->count() !== 1) {
            $this->error('Expected exactly one store owned by this email and connected to this Zid store ID. Nothing changed.');

            return self::FAILURE;
        }

        $store = $stores->sole();
        if (($store->settings['demo_seed'] ?? null) !== $email || ! filled($store->zid_authorization) || ! filled($store->zid_access_token)) {
            $this->error('The store must have its original demo_seed marker and real Zid credentials. Nothing changed.');

            return self::FAILURE;
        }

        // Use the queue's unique-job key so a queued/running sync is not disrupted.
        // An owned, expiring lock also releases safely after an interrupted command.
        $lock = Cache::lock(UniqueLock::getKey(new SyncStoreData($store)), 300);
        if (! $lock->get()) {
            $this->error('A Zid sync or cleanup is already queued/running. Wait for it to finish, then retry. Nothing changed.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($store, $email, $classifier): void {
                // Re-check the identity while locked, before considering any deletion.
                $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
                if ($locked->owner_id !== $store->owner_id || $locked->platform !== 'zid' || $locked->platform_store_id !== $store->platform_store_id
                    || ($locked->settings['demo_seed'] ?? null) !== $email) {
                    throw new RuntimeException('Store identity changed. Nothing changed; run the preview again.');
                }

                if ($locked->syncRuns()->where('type', 'zid_sync')->where('status', 'running')->exists()) {
                    throw new RuntimeException('A Zid sync is running. Wait for completion before cleaning the catalog.');
                }

                $products = $this->fixtures($locked, self::CATALOG_SIZES[$email]);
                $sales = ProductSale::query()->whereIn('product_id', $products->modelKeys())->orderBy('id')->lockForUpdate()->get();
                if ($sales->contains(fn (ProductSale $sale) => $sale->store_id !== $locked->id)) {
                    throw new RuntimeException('A fixture has sales assigned to another store. Nothing changed.');
                }

                $this->line("Owner: {$email} | Local store: {$locked->id} | Zid store: {$locked->platform_store_id}");
                $this->line("Matched demo products: {$products->count()} | Related sales rows: {$sales->count()}");
                $this->line('Other catalog products: '.($locked->products()->count() - $products->count()));

                if (! $this->option('apply')) {
                    $this->table(['ID', 'External ID', 'SKU', 'Name'], $products->map(fn (Product $p) => [
                        $p->id, $p->external_id, $p->sku, $p->name,
                    ])->all());
                    $this->info('Preview only. Nothing changed. Add --apply to delete these fixture products and their sales.');

                    return;
                }

                if ($products->isEmpty()) {
                    $this->info('No matching demo catalog remains. Nothing changed.');

                    return;
                }

                // Keep an exact database archive, in the same transaction, for recovery.
                // Credentials and store settings are deliberately not part of the archive.
                ActivityLog::query()->create([
                    'store_id' => $locked->id,
                    'action' => 'catalog.demo_cleared',
                    'subject_type' => Store::class,
                    'subject_id' => $locked->id,
                    'properties' => [
                        'email' => $email,
                        'zid_store_id' => $locked->platform_store_id,
                        'products_count' => $products->count(),
                        'sales_count' => $sales->count(),
                        'archive' => [
                            'products' => $products->map(fn (Product $p) => $p->getRawOriginal())->all(),
                            'product_sales' => $sales->map(fn (ProductSale $s) => $s->getRawOriginal())->all(),
                        ],
                    ],
                    'created_at' => now(),
                ]);

                // product_sales has a cascading foreign key to products.
                $locked->products()->whereKey($products->modelKeys())->delete();
                $classifier->classify($locked);
                $this->info('Demo catalog removed and remaining product performance recalculated.');
                $this->line('Deleted rows are archived in activity_logs under catalog.demo_cleared.');
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->line('Zid connection, account, other stores, evaluations, designs and market data are unchanged.');

        return self::SUCCESS;
    }

    /**
     * Match both identifiers used by the original seeder, including its duplicate.
     * Never delete all products, all missing images, or all IDs starting with Z.
     *
     * @return Collection<int, Product>
     */
    private function fixtures(Store $store, int $size): Collection
    {
        $skus = [];
        for ($n = 1; $n <= $size; $n++) {
            $externalId = 'Z'.(10000 + $n + $store->id * 1000);
            $skus[$externalId] = strtoupper(substr($store->slug, 0, 3)).'-'.(1000 + $n);
            if ($n === 1) {
                $skus[$externalId.'-dup'] = $skus[$externalId];
            }
        }

        return $store->products()->whereIn('external_id', array_keys($skus))
            ->whereNull('image_url')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Product $p) => isset($skus[$p->external_id]) && $p->sku === $skus[$p->external_id]);
    }
}
