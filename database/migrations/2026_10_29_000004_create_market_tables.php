<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // salla | zid | shopify | instagram | tiktok | snapchat
            $table->string('platform');
            $table->string('url')->nullable();
            $table->string('handle')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('competitor_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('previous_price', 10, 2)->nullable();
            $table->string('image_url')->nullable();
            $table->string('url')->nullable();
            $table->boolean('is_best_seller')->default(false);
            $table->json('features')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['competitor_id', 'external_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('url')->nullable();
            $table->text('caption')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->decimal('engagement_rate', 6, 2)->default(0);
            $table->json('features')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('store');
            $table->string('product_name')->nullable();
            $table->text('text');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('language', 5)->nullable();
            $table->string('sentiment')->nullable();
            $table->decimal('sentiment_score', 4, 2)->nullable();
            $table->json('topics')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // color | pattern | style | shape | material | theme
            $table->string('type');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('value')->nullable();
            $table->unsignedTinyInteger('momentum')->default(0);
            $table->decimal('growth', 6, 1)->default(0);
            $table->unsignedTinyInteger('saturation')->default(0);
            $table->json('platforms')->nullable();
            $table->json('categories')->nullable();
            $table->boolean('in_catalog')->default(false);
            // rising | stable | declining
            $table->string('status')->default('rising');
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trends');
        Schema::dropIfExists('customer_reviews');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('competitor_products');
        Schema::dropIfExists('competitors');
    }
};
