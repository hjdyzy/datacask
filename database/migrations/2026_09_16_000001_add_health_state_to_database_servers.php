<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->boolean('health_is_online')->nullable();
            $table->unsignedInteger('health_failure_count')->default(0);
            $table->unsignedInteger('health_success_count')->default(0);
            $table->timestamp('health_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn(['health_is_online', 'health_failure_count', 'health_success_count', 'health_checked_at']);
        });
    }
};
