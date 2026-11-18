<?php

namespace App\Services\Team;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who is responsible for which product. A product has one member at most:
 * a member takes only products nobody holds, and the write itself checks
 * that, so two members pressing at the same moment cannot both win.
 *
 * Writes go straight to the table: the columns are not mass-assignable
 * and a claim is not a change to the product, so updated_at stays put.
 */
class ProductAssignments
{
    /**
     * A member takes products for themselves. Products someone else
     * already holds are left alone and counted as "taken".
     *
     * @param  list<int>  $ids
     * @return array{claimed: int, taken: int, holder: string|null}
     */
    public function claim(Store $store, User $member, array $ids): array
    {
        $claimed = $this->products($store, $ids)
            ->whereNull('assigned_user_id')
            ->update(['assigned_user_id' => $member->id, 'assigned_at' => now()]);

        $others = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->whereNotNull('assigned_user_id')
            ->where('assigned_user_id', '!=', $member->id)
            ->with('assignee:id,name')
            ->get(['id', 'assigned_user_id']);

        if ($claimed > 0) {
            Activity::log('product.claimed', $member, ['count' => $claimed], $store->id);
        }

        return ['claimed' => $claimed, 'taken' => $others->count(), 'holder' => $others->first()?->assignee?->name];
    }

    /**
     * The owner gives products to a member, moving them from whoever
     * holds them now.
     *
     * @param  list<int>  $ids
     */
    public function assign(Store $store, User $member, array $ids): int
    {
        $moved = $this->products($store, $ids)
            ->where(fn (Builder $q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', '!=', $member->id))
            ->update(['assigned_user_id' => $member->id, 'assigned_at' => now()]);

        if ($moved > 0) {
            Activity::log('product.assigned', $member, ['count' => $moved, 'member' => $member->name], $store->id);
        }

        return $moved;
    }

    /**
     * Give products back: they are nobody's again.
     *
     * @param  list<int>  $ids
     */
    public function release(Store $store, User $member, array $ids): int
    {
        $released = $this->products($store, $ids)
            ->where('assigned_user_id', $member->id)
            ->update(['assigned_user_id' => null, 'assigned_at' => null]);

        if ($released > 0) {
            Activity::log('product.released', $member, ['count' => $released, 'member' => $member->name], $store->id);
        }

        return $released;
    }

    /**
     * Everything a member holds in this store goes to another member, or
     * back to nobody. Used when a member leaves the team.
     */
    public function handOver(Store $store, User $from, ?User $to = null): int
    {
        return DB::table('products')
            ->where('store_id', $store->id)
            ->where('assigned_user_id', $from->id)
            ->update(['assigned_user_id' => $to?->id, 'assigned_at' => $to ? now() : null]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function products(Store $store, array $ids): Builder
    {
        return DB::table('products')->where('store_id', $store->id)->whereIn('id', $ids);
    }
}
