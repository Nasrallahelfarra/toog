<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->text('target_audience')->nullable();
            $table->json('specialist_review')->nullable();
            $table->json('image_observations')->nullable();
        });

        Schema::create('design_improvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_key');
            $table->string('status')->default('queued');
            $table->json('recommendations');
            $table->json('source_snapshot');
            $table->string('source_image_path');
            $table->foreignId('design_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('result_evaluation_id')->nullable()->constrained('evaluations')->nullOnDelete();
            $table->json('verification')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'request_key']);
            $table->index(['evaluation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_improvements');
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn(['target_audience', 'specialist_review', 'image_observations']);
        });
    }
};
