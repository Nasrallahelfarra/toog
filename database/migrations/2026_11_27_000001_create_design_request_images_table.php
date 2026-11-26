<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The free idea as a look: the merchant's own pictures (hand sketches and
 * fabric photos), each with a role, uploaded before the request is sent
 * and attached to it on submit; how the look is presented; and which
 * images of a request failed, so a retry fills only those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_request_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Null while staged: uploaded, not yet sent with a request.
            $table->foreignId('design_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('path');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('original_name', 160)->nullable();
            $table->timestamps();

            $table->index(['design_request_id', 'position']);
            $table->index(['store_id', 'user_id', 'design_request_id']);
        });

        Schema::table('design_requests', function (Blueprint $table) {
            $table->json('presentation')->nullable()->after('source_image_path');
            $table->json('failures')->nullable()->after('error');
            // The planner's reading of the sketch and fabric, kept for retries.
            $table->json('plan')->nullable()->after('presentation');
        });

        Schema::table('designs', function (Blueprint $table) {
            $table->unsignedTinyInteger('variant')->nullable()->after('design_request_id');
            $table->string('view', 10)->nullable()->after('variant');
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropColumn(['variant', 'view']);
        });
        Schema::table('design_requests', function (Blueprint $table) {
            $table->dropColumn(['presentation', 'failures', 'plan']);
        });
        Schema::dropIfExists('design_request_images');
    }
};
