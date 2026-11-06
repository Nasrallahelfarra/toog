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
     * How order lines and months are read. Bump it when that changes: the
     * next sync then re-reads every order instead of only the changed ones.
     * 2 — lines keep their parent product; months follow the store's clock.
     */
    public const VERSION = 2;

    /**
     * One form for a Zid ID. Zid writes the same UUID with dashes on a
     * product and without them in an order line's parent_id.
     */
    public static function key(mixed $id): ?string
    {
        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        return str_replace('-', '', mb_strtolower(trim((string) $id)));
    }

    /**
     * Catalog product IDs by Zid ID, in the form key() gives.
     *
     * @param  iterable<array-key, int>  $products  product ID by Zid external ID
     * @return array<string, int>
     */
    public static function catalogKeys(iterable $products): array
    {
        $keys = [];
        foreach ($products as $external => $id) {
            if (($key = self::key($external)) !== null) {
                $keys[$key] = $id;
            }
        }

        return $keys;
    }

    /**
     * The catalog product an order line sold: the line's own product, or
     * for a size or color option, the product it belongs to.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $catalog  from catalogKeys()
     */
    public static function productFor(array $line, array $catalog): ?int
    {
        foreach ([$line['external_id'] ?? null, $line['parent_id'] ?? null] as $id) {
            $key = self::key($id);
            if ($key !== null && isset($catalog[$key])) {
                return $catalog[$key];
            }
        }

        return null;
    }

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
        $category = is_array($category) ? $this->localised($category['name'] ?? null) : $this->localised($category);

        // Fit the 255-character columns: strict MySQL rejects the whole save
        // for one overlong value. A cut URL would break, so drop it instead.
        $image = $this->image($p);

        return [
            'external_id' => (string) $id,
            'sku' => is_scalar($p['sku'] ?? null) ? mb_substr((string) $p['sku'], 0, 255) : null,
            'name' => mb_substr($name, 0, 255),
            'category' => $category === null ? null : mb_substr($category, 0, 255),
            'price' => $this->number($p['sale_price'] ?? null) ?: $this->number($p['price'] ?? null),
            'stock' => (int) ($p['quantity'] ?? $p['stock'] ?? 0),
            'image_url' => $image !== null && strlen($image) <= 255 ? $image : null,
            'launched_at' => $this->date($p['created_at'] ?? null),
        ];
    }

    /**
     * Order lines as [external_id, parent_id, units, revenue] plus the order month.
     *
     * @param  array<string, mixed>  $o
     * @return array{id: string|null, month: string, returned: bool, lines: list<array{external_id: string, parent_id?: string, units: int, revenue: float}>}|null
     */
    public function order(array $o): ?array
    {
        $status = mb_strtolower((string) ($o['order_status']['code'] ?? $o['order_status']['name'] ?? $o['status'] ?? ''));

        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return null;
        }

        $placed = $this->placedAt($o['created_at'] ?? $o['date'] ?? null);

        if (! $placed) {
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

            // A size or color option has its own ID; parent_id names the product.
            $parent = self::key($line['parent_id'] ?? null);

            $lines[] = ['external_id' => (string) $id, ...($parent !== null ? ['parent_id' => $parent] : []), 'units' => $units, 'revenue' => (float) $revenue];
        }

        return [
            // Lets the save step count an order once when read windows overlap.
            'id' => isset($o['id']) && is_scalar($o['id']) ? (string) $o['id'] : null,
            'month' => $placed->startOfMonth()->toDateString(),
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

    /**
     * When the order was placed, on the store's clock. Zid writes order
     * times in UTC without an offset ("2026-11-01 00:02:58" is 03:02 in
     * Riyadh), and its dashboard counts months in the store's time zone.
     */
    public function placedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone(config('mojo.zid.timezone', 'Asia/Riyadh'));
        } catch (\Throwable) {
            return null;
        }
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
