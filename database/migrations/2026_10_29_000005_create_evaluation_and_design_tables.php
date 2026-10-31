<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('design_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('evaluation_id')->nullable()->index();
            // modification | new_idea | mockup
            $table->string('type')->default('new_idea');
            $table->string('title')->nullable();
            $table->text('prompt');
            $table->string('category')->nullable();
            // product_photo | print_file | mockup_white | mockup_model | lifestyle | social
            $table->string('output_type')->default('product_photo');
            $table->string('aspect_ratio')->default('1:1');
            $table->unsignedTinyInteger('variants_count')->default(3);
            $table->string('source_image_path')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('evaluation_id')->nullable()->index();
            $table->string('title');
            $table->string('image_path');
            $table->string('output_type')->default('product_photo');
            $table->string('aspect_ratio')->default('1:1');
            $table->json('palette')->nullable();
            $table->json('tags')->nullable();
            // draft | in_review | approved | rejected
            $table->string('status')->default('draft')->index();
            $table->boolean('is_favorite')->default(false);
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->decimal('cost', 10, 6)->default(0);
            $table->text('prompt')->nullable();
            $table->timestamps();
        });

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('evaluation_batches')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('evaluations')->nullOnDelete();
            $table->unsignedBigInteger('root_id')->nullable()->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('design_id')->nullable()->constrained()->nullOnDelete();
            // manual | batch | design
            $table->string('source')->default('manual');

            // Candidate data (FR-3.1)
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('material')->nullable();
            $table->json('colors')->nullable();
            $table->string('pattern')->nullable();
            $table->string('style')->nullable();
            $table->string('season')->nullable();
            $table->string('occasion')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Result (FR-3.2 .. FR-3.6)
            // pending | processing | completed | failed
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('breakdown')->nullable();
            // accept | reject | modify
            $table->string('recommendation')->nullable()->index();
            $table->text('explanation_ar')->nullable();
            $table->text('explanation_en')->nullable();
            $table->json('top_factors')->nullable();
            $table->json('modifications')->nullable();
            $table->unsignedTinyInteger('expected_score')->nullable();
            $table->json('cultural_flags')->nullable();
            $table->json('alternatives')->nullable();
            $table->string('suggested_launch')->nullable();
            $table->boolean('is_novel')->default(false);

            // Final human decision (FR-3.9)
            // approved | rejected | needs_changes
            $table->string('final_decision')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->decimal('ai_cost', 10, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('designs');
        Schema::dropIfExists('design_requests');
        Schema::dropIfExists('evaluation_batches');
    }
};
