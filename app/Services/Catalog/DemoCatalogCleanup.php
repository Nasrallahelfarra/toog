<?php

namespace App\Services\Catalog;

use App\Jobs\SyncStoreData;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DemoCatalogCleanup
{
    // Original catalog sizes in DemoStoresSeeder, before its single duplicate.
    private const CATALOG_SIZES = [
        'lama@demo.sa' => 42,
        'hadaya@demo.sa' => 35,
        'najd@demo.sa' => 14,
    ];

    public function __construct(private readonly PerformanceClassifier $classifier) {}

    public function supports(string $email): bool
    {
        return isset(self::CATALOG_SIZES[$email]);
    }

    public function eligible(Store $store): bool
    {
        $email = $store->settings['demo_seed'] ?? null;

        return is_string($email) && $this->supports($email)
            && $store->owner?->email === $email
            && $store->platform === 'zid'
            && ctype_digit((string) $store->platform_store_id) && (int) $store->platform_store_id > 0
            && filled($store->zid_authorization) && filled($store->zid_access_token);
    }

    public function availableInDashboard(Store $store, User $user): bool
    {
        return (bool) config('mojo.demo_catalog_cleanup_enabled')
            && ($user->isAdmin() || $user->id === $store->owner_id)
            && $this->eligible($store);
    }

    /** @return array<string, mixed> */
    public function preview(Store $store): array
    {
        $this->assertEligible($store);

        return $this->summary($store, $this->snapshot($store));
    }

    /** @return array<string, mixed> */
    public function run(Store $store, bool $apply, ?User $actor = null, ?string $previewToken = null): array
    {
        $this->assertEligible($store);
        $lock = Cache::lock(UniqueLock::getKey(new SyncStoreData($store)), 300);
        if (! $lock->get()) {
            throw new DemoCatalogCleanupException('busy', 'A Zid sync or cleanup is already queued/running. Wait for it to finish, then retry. Nothing changed.');
        }

        try {
            return DB::transaction(function () use ($store, $apply, $actor, $previewToken): array {
                $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
                if ($locked->owner_id !== $store->owner_id || $locked->platform_store_id !== $store->platform_store_id) {
                    throw new DemoCatalogCleanupException('changed', 'Store identity changed. Nothing changed; run the preview again.');
                }
                $this->assertEligible($locked);

                if ($this->running($locked)) {
                    throw new DemoCatalogCleanupException('busy', 'A Zid sync is running. Wait for completion before cleaning the catalog.');
                }

                $snapshot = $this->snapshot($locked, lock: true);
                $result = $this->summary($locked, $snapshot);
                if (! $apply || $snapshot['products']->isEmpty()) {
                    return [...$result, 'deleted' => false];
                }

                if ($previewToken !== null && ! hash_equals($snapshot['preview_token'], $previewToken)) {
                    throw new DemoCatalogCleanupException('changed', 'Catalog changed since the preview. Refresh the preview before deleting. Nothing changed.');
                }

                if ($snapshot['sales']->contains(fn (ProductSale $sale) => $sale->store_id !== $locked->id)) {
                    throw new DemoCatalogCleanupException('inconsistent', 'A fixture has sales assigned to another store. Nothing changed.');
                }

                // Archive only the affected rows, atomically with their deletion.
                ActivityLog::query()->create([
                    'store_id' => $locked->id,
                    'user_id' => $actor?->id,
                    'acting_as_admin' => $actor?->isAdmin() ?? false,
                    'action' => 'catalog.demo_cleared',
                    'subject_type' => Store::class,
                    'subject_id' => $locked->id,
                    'properties' => [
                        'email' => $locked->settings['demo_seed'],
                        'zid_store_id' => $locked->platform_store_id,
                        'products_count' => $result['products_count'],
                        'sales_count' => $result['sales_count'],
                        'archive' => [
                            'products' => $snapshot['products']->map(fn (Product $p) => $p->getRawOriginal())->values()->all(),
                            'product_sales' => $snapshot['sales']->map(fn (ProductSale $s) => $s->getRawOriginal())->values()->all(),
                        ],
                    ],
                    'created_at' => now(),
                ]);

                // Only these fixtures' sales cascade; imports and credentials stay intact.
                $locked->products()->whereKey($snapshot['products']->modelKeys())->delete();
                $this->classifier->classify($locked);

                return [...$result, 'deleted' => true];
            });
        } finally {
            $lock->release();
        }
    }

    private function assertEligible(Store $store): void
    {
        if (! $this->eligible($store)) {
            throw new DemoCatalogCleanupException('ineligible', 'The store must have its original demo_seed marker and real Zid credentials. Nothing changed.');
        }
    }

    private function running(Store $store): bool
    {
        return $store->syncRuns()->where('type', 'zid_sync')->where('status', 'running')->exists();
    }

    /** @return array{products: Collection<int, Product>, sales: Collection<int, ProductSale>, preview_token: string} */
    private function snapshot(Store $store, bool $lock = false): array
    {
        $skus = [];
        $size = self::CATALOG_SIZES[$store->settings['demo_seed']];
        for ($n = 1; $n <= $size; $n++) {
            $externalId = 'Z'.(10000 + $n + $store->id * 1000);
            $skus[$externalId] = strtoupper(substr($store->slug, 0, 3)).'-'.(1000 + $n);
            if ($n === 1) {
                $skus[$externalId.'-dup'] = $skus[$externalId];
            }
        }

        $products = $store->products()->whereIn('external_id', array_keys($skus))
            ->whereNull('image_url')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn (Product $p) => isset($skus[$p->external_id]) && $p->sku === $skus[$p->external_id])->values();
        $sales = ProductSale::query()->whereIn('product_id', $products->modelKeys())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();

        // Bind web confirmation to this store and the exact rows shown in the preview.
        $previewToken = hash_hmac('sha256', json_encode([
            $store->id, $store->owner_id, $store->platform_store_id,
            $products->map(fn (Product $p) => $p->getRawOriginal())->all(),
            $sales->map(fn (ProductSale $s) => $s->getRawOriginal())->all(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['products' => $products, 'sales' => $sales, 'preview_token' => $previewToken];
    }

    /**
     * @param  array{products: Collection<int, Product>, sales: Collection<int, ProductSale>, preview_token: string}  $snapshot
     * @return array<string, mixed>
     */
    private function summary(Store $store, array $snapshot): array
    {
        return [
            'products_count' => $snapshot['products']->count(),
            'sales_count' => $snapshot['sales']->count(),
            'remaining_count' => $store->products()->count() - $snapshot['products']->count(),
            'preview_token' => $snapshot['preview_token'],
            'sync_running' => $this->running($store),
            'items' => $snapshot['products']->map(fn (Product $p) => $p->only(['id', 'external_id', 'sku', 'name']))->all(),
        ];
    }
}
