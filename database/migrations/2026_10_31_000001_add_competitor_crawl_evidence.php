<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitors', function (Blueprint $table) {
            $table->json('analysis')->nullable();
        });
        Schema::table('competitor_products', function (Blueprint $table) {
            $table->json('evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('competitors', fn (Blueprint $table) => $table->dropColumn('analysis'));
        Schema::table('competitor_products', fn (Blueprint $table) => $table->dropColumn('evidence'));
    }
};
