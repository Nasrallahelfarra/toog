<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store staff: members the owner adds with a limited set of dashboard
 * sections, and the products each of them is responsible for. A product
 * has one responsible member at most, so two members can never hold it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_user', function (Blueprint $table) {
            // Section keys a staff member may open; unused for owners.
            $table->json('permissions')->nullable()->after('role');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('store_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_user_id');
            $table->index(['store_id', 'assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'assigned_user_id']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn('assigned_at');
        });

        Schema::table('store_user', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
