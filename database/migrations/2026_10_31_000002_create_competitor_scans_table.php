<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->string('stage')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('metrics')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['competitor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_scans');
    }
};
