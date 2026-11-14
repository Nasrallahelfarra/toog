<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews imported from Zid keep Zid's id (so a re-import updates rather
 * than duplicates), the product they belong to and when they were written.
 * Seeded sample reviews have no external id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('source');
            $table->foreignId('product_id')->nullable()->after('product_name')->constrained()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('topics');
            $table->json('evidence')->nullable()->after('reviewed_at');
            $table->unique(['store_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'source', 'external_id']);
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['external_id', 'reviewed_at', 'evidence']);
        });
    }
};
