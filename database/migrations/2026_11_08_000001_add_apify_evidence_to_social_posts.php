<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posts read through Apify keep the platform's post id, so a rescan
 * updates a post instead of duplicating it, plus what was observed.
 * Instagram and Facebook image links are longer than 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('competitor_id');
            $table->json('evidence')->nullable()->after('features');
            $table->text('url')->nullable()->change();
            $table->text('thumbnail_url')->nullable()->change();
            $table->unique(['competitor_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique(['competitor_id', 'external_id']);
            $table->dropColumn(['external_id', 'evidence']);
            $table->string('url')->nullable()->change();
            $table->string('thumbnail_url')->nullable()->change();
        });
    }
};
