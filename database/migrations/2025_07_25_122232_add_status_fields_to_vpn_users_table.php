<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('is_online')->default(false)->after('is_active');
            $table->timestamp('last_seen_at')->nullable()->after('is_online');
            $table->string('last_ip')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'is_online', 'last_seen_at', 'last_ip']);
        });
    }
};
