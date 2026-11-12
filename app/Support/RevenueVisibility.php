<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Keeps revenue out of the store dashboard while the platform admin has it
 * switched off for that store. Revenue keys are blanked on the server, so
 * the figures never reach the browser, not just hidden by the page.
 */
class RevenueVisibility
{
    /** Keys that carry money earned, or a share derived from it. */
    public const KEYS = ['revenue', 'month_revenue', 'revenue_share', 'revenue_delta', 'revenue_spark'];

    /**
     * @template T
     *
     * @param  T  $data
     * @return T
     */
    public static function apply(Store $store, mixed $data): mixed
    {
        return $store->showsRevenue() ? $data : self::scrub($data);
    }

    public static function scrub(mixed $data): mixed
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                $data[$key] = null;

                continue;
            }

            if (is_array($value) || $value instanceof Arrayable) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
