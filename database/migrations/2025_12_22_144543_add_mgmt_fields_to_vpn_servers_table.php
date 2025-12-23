<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        if (!Schema::hasColumn('vpn_servers', 'online_users')) {
            $table->unsignedInteger('online_users')->default(0)->after('name');
        }
        if (!Schema::hasColumn('vpn_servers', 'last_mgmt_at')) {
            $table->timestamp('last_mgmt_at')->nullable()->after('online_users');
        }
    });
}

public function down(): void
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        if (Schema::hasColumn('vpn_servers', 'last_mgmt_at')) $table->dropColumn('last_mgmt_at');
        if (Schema::hasColumn('vpn_servers', 'online_users')) $table->dropColumn('online_users');
    });
}

};
