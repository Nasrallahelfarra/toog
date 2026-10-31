<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('store_owner')->index()->after('password');
            $table->string('status')->default('active')->index()->after('role');
            $table->string('locale', 5)->default('ar')->after('status');
            $table->string('phone')->nullable()->after('email');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'locale', 'phone', 'last_login_at', 'disabled_at', 'disabled_reason']);
        });
    }
};
