<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('image_url')->nullable();
            // colors, color_family, typography, pattern, pattern_complexity,
            // material, season, occasion, style, price_tier
            $table->json('features')->nullable();
            $table->string('performance')->nullable()->index();
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedInteger('units_sold')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->decimal('add_to_cart_rate', 5, 2)->default(0);
            $table->decimal('return_rate', 5, 2)->default(0);
            $table->unsignedInteger('turnover_days')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_novel')->default(false);
            $table->date('launched_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'external_id']);
        });

        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('add_to_cart')->default(0);
            $table->unsignedInteger('returns')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'period']);
            $table->index(['store_id', 'period']);
        });

        Schema::create('seasonal_events', function (Blueprint $table) {
            $table->id();
            // null store_id = platform-wide default (Ramadan, National Day...)
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('season_key')->nullable()->index();
            $table->string('name_ar');
            $table->string('name_en');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('prep_weeks')->default(6);
            $table->json('categories')->nullable();
            $table->json('palette')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonal_events');
        Schema::dropIfExists('product_sales');
        Schema::dropIfExists('products');
    }
};
