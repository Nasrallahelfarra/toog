<?php

namespace App\Services\Imports;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Analytics\StorePerformance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * File import, the alternative to the Zid connection (FR-1.1): a products
 * file and a monthly sales file. Valid rows are imported; invalid rows are
 * skipped and reported with their row number and reason.
 */
class CatalogImporter
{
    public const PRODUCT_COLUMNS = ['external_id', 'name', 'category', 'price', 'stock', 'image_url', 'colors', 'pattern', 'material', 'style', 'season', 'launched_at'];

    public const SALES_COLUMNS = ['product', 'month', 'orders', 'units', 'revenue', 'views', 'add_to_cart', 'returns'];

    public const MAX_REPORTED_ERRORS = 25;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PerformanceClassifier $classifier,
    ) {}

    /**
     * @return array{type: string, imported: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function products(Store $store, string $path, string $extension): array
    {
        $data = $this->load($path, $extension, ['name']);
        $errors = [];
        $imported = 0;

        DB::transaction(function () use ($store, $data, &$errors, &$imported) {
            foreach ($data['rows'] as $i => $row) {
                $line = $i + 2;

                if (($row['name'] ?? '') === '') {
                    $errors[] = ['row' => $line, 'message' => __('validation.required', ['attribute' => 'name'])];

                    continue;
                }

                if (($row['price'] ?? '') !== '' && (! is_numeric($row['price']) || (float) $row['price'] < 0)) {
                    $errors[] = ['row' => $line, 'message' => __('validation.numeric', ['attribute' => 'price'])];

                    continue;
                }

                $launched = null;
                if (($row['launched_at'] ?? '') !== '') {
                    try {
                        $launched = Carbon::parse($row['launched_at'])->toDateString();
                    } catch (\Throwable) {
                        $errors[] = ['row' => $line, 'message' => __('validation.date', ['attribute' => 'launched_at'])];

                        continue;
                    }
                }

                $colors = collect(preg_split('/[|,،]+/u', $row['colors'] ?? '') ?: [])
                    ->map(fn ($c) => StorePerformance::vocabularyKey('colors', $c))
                    ->filter()->unique()->values()->all();

                $features = array_filter([
                    'colors' => $colors ?: null,
                    'pattern' => StorePerformance::vocabularyKey('patterns', $row['pattern'] ?? null),
                    'material' => StorePerformance::vocabularyKey('materials', $row['material'] ?? null),
                    'style' => StorePerformance::vocabularyKey('styles', $row['style'] ?? null),
                    'season' => StorePerformance::vocabularyKey('seasons', $row['season'] ?? null),
                ]);

                $externalId = ($row['external_id'] ?? '') !== '' ? $row['external_id'] : null;
                $product = $externalId
                    ? Product::query()->firstOrNew(['store_id' => $store->id, 'external_id' => $externalId])
                    : Product::query()->firstOrNew(['store_id' => $store->id, 'name' => $row['name']]);

                $product->fill(array_filter([
                    'name' => $row['name'],
                    'category' => ($row['category'] ?? '') ?: null,
                    'price' => ($row['price'] ?? '') !== '' ? (float) $row['price'] : null,
                    'stock' => ($row['stock'] ?? '') !== '' ? (int) $row['stock'] : null,
                    'image_url' => ($row['image_url'] ?? '') ?: null,
                    'launched_at' => $launched,
                ], fn ($v) => $v !== null));

                if ($features) {
                    $product->features = array_merge($product->features ?? [], $features);
                }

                $product->save();
                $imported++;
            }
        });

        return $this->finish($store, 'products', $imported, $errors);
    }

    /**
     * @return array{type: string, imported: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function sales(Store $store, string $path, string $extension): array
    {
        $data = $this->load($path, $extension, ['product', 'month', 'units']);
        $products = Product::query()->where('store_id', $store->id)->get(['id', 'external_id', 'sku', 'name']);
        $byExternal = $products->whereNotNull('external_id')->keyBy('external_id');
        $bySku = $products->whereNotNull('sku')->keyBy('sku');
        $byName = $products->keyBy(fn (Product $p) => mb_strtolower($p->name));

        $errors = [];
        $imported = 0;

        DB::transaction(function () use ($store, $data, $byExternal, $bySku, $byName, &$errors, &$imported) {
            foreach ($data['rows'] as $i => $row) {
                $line = $i + 2;
                $ref = $row['product'] ?? '';
                $product = $byExternal->get($ref) ?? $bySku->get($ref) ?? $byName->get(mb_strtolower($ref));

                if (! $product) {
                    $errors[] = ['row' => $line, 'message' => "Unknown product “{$ref}”"];

                    continue;
                }

                if (! preg_match('/^\d{4}-\d{1,2}(-\d{1,2})?$/', $row['month'] ?? '')) {
                    $errors[] = ['row' => $line, 'message' => 'month must look like 2026-03'];

                    continue;
                }

                $numbers = [];
                foreach (['orders', 'units', 'revenue', 'views', 'add_to_cart', 'returns'] as $field) {
                    $value = $row[$field] ?? '';

                    if ($value !== '' && (! is_numeric($value) || (float) $value < 0)) {
                        $errors[] = ['row' => $line, 'message' => __('validation.numeric', ['attribute' => $field])];

                        continue 2;
                    }

                    $numbers[$field] = $value === '' ? 0 : ($field === 'revenue' ? (float) $value : (int) $value);
                }

                if ($numbers['returns'] > $numbers['units']) {
                    $errors[] = ['row' => $line, 'message' => 'returns cannot exceed units'];

                    continue;
                }

                [$y, $m] = array_map('intval', explode('-', $row['month']));

                ProductSale::query()->updateOrCreate(
                    ['product_id' => $product->id, 'period' => Carbon::create($y, $m, 1)->startOfDay()],
                    ['store_id' => $store->id, ...$numbers],
                );
                $imported++;
            }
        });

        if ($imported > 0) {
            $this->classifier->classify($store);
        }

        return $this->finish($store, 'sales', $imported, $errors);
    }

    /**
     * A downloadable template with the expected header and example rows.
     */
    public function template(string $type): string
    {
        $rows = $type === 'sales'
            ? [self::SALES_COLUMNS, ['Z10001', now()->subMonth()->format('Y-m'), '42', '45', '15300', '1350', '120', '2'], ['Z10002', now()->format('Y-m'), '18', '18', '6480', '700', '45', '1']]
            : [self::PRODUCT_COLUMNS, ['Z10001', 'عباية كريب سوداء بتطريز', 'عبايات', '340', '35', 'https://example.com/a.jpg', 'black|sand', 'embroidery', 'crepe', 'luxury', 'ramadan', now()->subMonths(6)->toDateString()], ['Z10002', 'طرحة شيفون بيج', 'طرح', '95', '80', '', 'beige', 'solid', 'chiffon', 'minimal', 'all_year', '']];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  list<string>  $required
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    private function load(string $path, string $extension, array $required): array
    {
        try {
            $data = $this->reader->read($path, $extension);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['file' => __('validation.required', ['attribute' => 'rows'])]);
        }

        $missing = array_diff($required, $data['headers']);

        if ($missing) {
            throw ValidationException::withMessages([
                'file' => 'Missing columns: '.implode(', ', $missing).'. Download the template to see the expected header.',
            ]);
        }

        return $data;
    }

    /**
     * @param  list<array{row: int, message: string}>  $errors
     * @return array{type: string, imported: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    private function finish(Store $store, string $type, int $imported, array $errors): array
    {
        $report = [
            'type' => $type,
            'imported' => $imported,
            'skipped' => count($errors),
            'errors' => array_slice($errors, 0, self::MAX_REPORTED_ERRORS),
        ];

        SyncRun::query()->create([
            'store_id' => $store->id,
            'type' => 'file_import',
            'status' => $imported > 0 ? 'completed' : 'failed',
            'stats' => ['file' => $type, 'imported' => $imported, 'skipped' => count($errors)],
            'error' => $imported > 0 ? null : ($errors[0]['message'] ?? null),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $report;
    }
}
