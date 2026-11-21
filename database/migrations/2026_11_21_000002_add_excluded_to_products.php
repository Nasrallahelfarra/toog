<?php

use App\Support\ExcludedProducts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accessories are left out of the platform: the store's own and the ones
 * read from competitors. They are marked, not deleted: the row and its
 * sales stay in the database and every page skips them. Existing products
 * are marked here; new ones when they are saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'competitor_products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('excluded')->default(false)->after('store_id');
                $table->index(['store_id', 'excluded']);
            });

            DB::table($name)->select(['id', 'name', 'category'])->orderBy('id')->chunkById(500, function ($products) use ($name) {
                $ids = $products->filter(fn ($p) => ExcludedProducts::matches($p->category, $p->name))->pluck('id')->all();

                if ($ids !== []) {
                    DB::table($name)->whereIn('id', $ids)->update(['excluded' => true]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'competitor_products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['store_id', 'excluded']);
                $table->dropColumn('excluded');
            });
        }
    }
};
