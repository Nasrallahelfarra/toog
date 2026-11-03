<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->json('snapshot');
            $table->json('review')->nullable();
            $table->string('source_image_path')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
        Schema::table('design_requests', function (Blueprint $table) {
            $table->foreignId('product_recommendation_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('design_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_recommendation_id'));
        Schema::dropIfExists('product_recommendations');
    }
};
