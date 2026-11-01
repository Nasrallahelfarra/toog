<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_improvements', function (Blueprint $table) {
            $table->foreignId('parent_improvement_id')->nullable()->constrained('design_improvements')->nullOnDelete();
            $table->text('custom_instruction')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_improvements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_improvement_id');
            $table->dropColumn('custom_instruction');
        });
    }
};
