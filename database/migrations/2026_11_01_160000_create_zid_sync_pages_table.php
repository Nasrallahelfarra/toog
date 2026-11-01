<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zid_sync_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->string('phase', 16);
            $table->unsignedInteger('page');
            $table->string('fingerprint', 64);
            $table->json('rows');
            $table->unique(['sync_run_id', 'phase', 'page']);
            $table->index(['sync_run_id', 'phase', 'fingerprint'], 'zid_sync_page_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_sync_pages');
    }
};
