<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The competitor radar: each scan keeps a dated copy of a post's numbers
     * (scans overwrite the post itself), so engagement speed and rising
     * ideas can be measured over time; the merchant can watch a competitor
     * product; and the AI reading of the radar is kept per window.
     */
    public function up(): void
    {
        Schema::create('social_post_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('observed_at');
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('followers')->nullable();
            $table->index(['social_post_id', 'observed_at']);
        });

        Schema::table('competitor_products', function (Blueprint $table) {
            $table->timestamp('watched_at')->nullable();
        });

        Schema::create('competitor_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('days');
            $table->json('report');
            $table->boolean('from_model')->default(false);
            $table->timestamps();
            $table->index(['store_id', 'days', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_readings');
        Schema::table('competitor_products', fn (Blueprint $table) => $table->dropColumn('watched_at'));
        Schema::dropIfExists('social_post_observations');
    }
};
