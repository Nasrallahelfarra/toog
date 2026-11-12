<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each competitor post is read by AI once: what product it shows and why
     * it likely engaged. Scans never overwrite this, so reading the same
     * posts again costs nothing.
     */
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('ai_reading')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', fn (Blueprint $table) => $table->dropColumn('ai_reading'));
    }
};
