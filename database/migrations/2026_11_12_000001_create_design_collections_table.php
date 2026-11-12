<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI fashion designer: a brief (fabric, garment, occasion…) becomes
     * a small collection of design concepts, each with its own design sheet
     * and image. The designer's briefing (alerts and advice) is kept apart
     * so it can be refreshed without redesigning anything.
     */
    public function up(): void
    {
        Schema::create('design_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->json('brief');
            $table->string('fabric_image_path')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('story_ar')->nullable();
            $table->text('story_en')->nullable();
            $table->json('concepts')->nullable();
            $table->boolean('from_model')->default(false);
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        Schema::create('designer_briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('advice');
            $table->boolean('from_model')->default(false);
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        Schema::table('designs', function (Blueprint $table) {
            $table->foreignId('design_collection_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('designs', fn (Blueprint $table) => $table->dropConstrainedForeignId('design_collection_id'));
        Schema::dropIfExists('designer_briefings');
        Schema::dropIfExists('design_collections');
    }
};
