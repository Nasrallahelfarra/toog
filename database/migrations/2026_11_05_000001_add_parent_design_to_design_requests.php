<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refinement is a request that edits an earlier design; keeping the
     * link lets the studio compare the edit with what it started from.
     */
    public function up(): void
    {
        Schema::table('design_requests', function (Blueprint $table) {
            $table->foreignId('parent_design_id')->nullable()->constrained('designs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('design_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_design_id'));
    }
};
