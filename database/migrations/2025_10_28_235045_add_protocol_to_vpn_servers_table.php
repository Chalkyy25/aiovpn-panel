<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            // Add a protocol column (nullable just in case)
            if (!Schema::hasColumn('vpn_servers', 'protocol')) {
                $table->string('protocol', 20)
                      ->default('openvpn')
                      ->after('ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_servers', 'protocol')) {
                $table->dropColumn('protocol');
            }
        });
    }
};
