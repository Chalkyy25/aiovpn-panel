<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->string('wireguard_private_key')->nullable()->after('password');
            $table->string('wireguard_public_key')->nullable()->after('wireguard_private_key');
            $table->string('wireguard_address')->nullable()->after('wireguard_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropColumn(['wireguard_private_key', 'wireguard_public_key', 'wireguard_address']);
        });
    }
};



