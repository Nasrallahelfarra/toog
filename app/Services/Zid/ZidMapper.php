<?php

namespace App\Services\Zid;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Turns Zid payloads into application rows. Tolerant of the field variations seen
 * across Zid API versions (localised names, nested images and categories).
 */
class ZidMapper
{
    public const CANCELLED_STATUSES = ['cancelled', 'canceled', 'failed', 'deleted'];

    public const RETURNED_STATUSES = ['returned', 'refunded', 'reversed'];

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>|null
     */
    public function product(array $p): ?array
    {
        $id = $p['id'] ?? $p['product_id'] ?? null;
        $name = $this->localised($p['name'] ?? $p['title'] ?? null);

        if ($id === null || $name === null) {
            return null;
        }

        $category = Arr::first($p['categories'] ?? []) ?? ($p['category'] ?? null);

        return [
            'external_id' => (string) $id,
            'sku' => $p['sku'] ?? null,
            'name' => $name,
            'category' => is_array($category) ? $this->localised($category['name'] ?? null) : $this->localised($category),
            'price' => $this->number($p['sale_price'] ?? null) ?: $this->number($p['price'] ?? null),
            'stock' => (int) ($p['quantity'] ?? $p['stock'] ?? 0),
            'image_url' => $this->image($p),
            'launched_at' => $this->date($p['created_at'] ?? null),
        ];
    }

    /**
     * Order lines as [external_id, units, revenue] plus the order month.
     *
     * @param  array<string, mixed>  $o
     * @return array{month: string, returned: bool, lines: list<array{external_id: string, units: int, revenue: float}>}|null
     */
    public function order(array $o): ?array
    {
        $status = mb_strtolower((string) ($o['order_status']['code'] ?? $o['order_status']['name'] ?? $o['status'] ?? ''));

        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return null;
        }

        $date = $this->date($o['created_at'] ?? $o['date'] ?? null);

        if (! $date) {
            return null;
        }

        $lines = [];
        foreach ($o['products'] ?? $o['items'] ?? [] as $line) {
            $id = $line['product_id'] ?? $line['id'] ?? null;

            if ($id === null) {
                continue;
            }

            $units = (int) ($line['quantity'] ?? 1);
            $revenue = $this->number($line['total'] ?? null) ?? ($this->number($line['price'] ?? null) ?? 0) * $units;

            $lines[] = ['external_id' => (string) $id, 'units' => $units, 'revenue' => (float) $revenue];
        }

        return [
            'month' => Carbon::parse($date)->startOfMonth()->toDateString(),
            'returned' => in_array($status, self::RETURNED_STATUSES, true),
            'lines' => $lines,
        ];
    }

    private function localised(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['ar'] ?? $value['en'] ?? Arr::first($value);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function image(array $p): ?string
    {
        $image = Arr::first($p['images'] ?? []);

        $url = is_array($image)
            ? ($image['image']['medium'] ?? $image['image']['full_size'] ?? $image['image']['small'] ?? $image['url'] ?? null)
            : $image;

        $url ??= $p['main_image'] ?? $p['image'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    private function number(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['value'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
