<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->boolean('supports_openvpn')->default(true)->after('protocol');
            $table->boolean('supports_wireguard')->default(false)->after('supports_openvpn');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn(['supports_openvpn', 'supports_wireguard']);
        });
    }
};
