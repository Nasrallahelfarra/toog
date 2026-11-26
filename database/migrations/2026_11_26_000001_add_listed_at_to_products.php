<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the product was created in the store, to the second. launched_at
 * keeps only the day, so products added the same day had no order among
 * themselves; "newest first" lists now sort by this, as Zid does. Until the
 * next product sync fills in the time, it starts at the launch day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dateTime('listed_at')->nullable()->after('launched_at');
            $table->index(['store_id', 'listed_at']);
        });

        DB::table('products')->whereNotNull('launched_at')->update(['listed_at' => DB::raw('launched_at')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'listed_at']);
            $table->dropColumn('listed_at');
        });
    }
};
