<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant's own designer choices: fabrics that are not in the
 * built-in list, and store sections added by hand (each designed as one
 * of the designer's garments). Names are unique per store and type in
 * their normalised form, so "شيفون" and "شِيفون" are the same fabric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designer_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // fabric | section
            $table->string('type', 20);
            $table->string('label', 80);
            $table->string('normalized', 80);
            // For a section: the garment the designer designs it as.
            $table->string('garment', 40)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'type', 'normalized']);
        });

        Schema::table('design_collections', function (Blueprint $table) {
            // The original piece of a "design like this" collection, copied.
            $table->string('reference_image_path')->nullable()->after('fabric_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('design_collections', function (Blueprint $table) {
            $table->dropColumn('reference_image_path');
        });
        Schema::dropIfExists('designer_options');
    }
};
