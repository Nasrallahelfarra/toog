<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stagnation is judged by sales alone: how long a product has gone without
 * a sale. Stock quantities are not a signal (stores that make pieces to
 * order carry numbers that are not real stock). The classifier saves the
 * quiet period here so lists can rank and explain by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Null while the sales data cannot show a quiet period.
            $table->unsignedSmallInteger('no_sales_days')->nullable()->after('turnover_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('no_sales_days');
        });
    }
};
