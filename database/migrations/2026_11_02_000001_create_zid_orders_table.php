<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Normalized Zid orders (no customer details), so a sync applies only changes. */
    public function up(): void
    {
        Schema::create('zid_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->date('month');
            $table->boolean('returned')->default(false);
            $table->json('lines');
            $table->unique(['store_id', 'external_id']);
            $table->index(['store_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_orders');
    }
};
