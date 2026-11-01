<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Catalog\DemoCatalogCleanup;
use Illuminate\Console\Command;
use RuntimeException;

class ClearDemoCatalog extends Command
{
    protected $signature = 'demo:clear-catalog
        {email : Owner email used by DemoStoresSeeder}
        {--zid-store= : Expected connected Zid store ID}
        {--apply : Delete the previewed fixture catalog; without this option nothing changes}';

    protected $description = 'Preview or remove seeded catalog products and their sales from one connected demo store';

    public function handle(DemoCatalogCleanup $cleanup): int
    {
        $email = (string) $this->argument('email');
        $zidStore = (string) $this->option('zid-store');

        if (! $cleanup->supports($email) || ! ctype_digit($zidStore) || (int) $zidStore < 1) {
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
        try {
            $result = $cleanup->run($store, (bool) $this->option('apply'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Owner: {$email} | Local store: {$store->id} | Zid store: {$store->platform_store_id}");
        $this->line("Matched demo products: {$result['products_count']} | Related sales rows: {$result['sales_count']}");
        $this->line('Other catalog products: '.$result['remaining_count']);

        if (! $this->option('apply')) {
            $this->table(['ID', 'External ID', 'SKU', 'Name'], array_map(array_values(...), $result['items']));
            $this->info('Preview only. Nothing changed. Add --apply to delete these fixture products and their sales.');
        } elseif ($result['deleted']) {
            $this->info('Demo catalog removed and remaining product performance recalculated.');
            $this->line('Deleted rows are archived in activity_logs under catalog.demo_cleared.');
        } else {
            $this->info('No matching demo catalog remains. Nothing changed.');
        }

        $this->line('Zid connection, account, other stores, evaluations, designs and market data are unchanged.');

        return self::SUCCESS;
    }
}
