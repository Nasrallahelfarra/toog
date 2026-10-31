<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('platform')->default('zid');
            $table->string('platform_store_id')->nullable();
            $table->string('domain')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('category')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('suspended_reason')->nullable();

            // Zid connection (tokens are stored encrypted)
            $table->string('connection_status')->default('disconnected');
            $table->text('zid_authorization')->nullable();
            $table->text('zid_access_token')->nullable();
            $table->text('zid_refresh_token')->nullable();
            $table->timestamp('zid_token_expires_at')->nullable();
            $table->string('sync_frequency')->default('daily');
            $table->timestamp('last_synced_at')->nullable();

            // Decision settings, balance ratio, criteria weights, brand guide
            $table->json('settings')->nullable();
            $table->json('brand_guide')->nullable();
            $table->decimal('ai_monthly_budget', 10, 2)->nullable();

            $table->timestamps();
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->unique(['store_id', 'user_id']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('running');
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
