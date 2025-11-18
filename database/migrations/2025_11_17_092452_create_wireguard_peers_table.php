<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wireguard_peers', function (Blueprint $table) {
            $table->id();

            // FK: VPN server
            $table->foreignId('vpn_server_id')
                ->constrained('vpn_servers')
                ->cascadeOnDelete();

            // FK: VPN user
            $table->foreignId('vpn_user_id')
                ->constrained('vpn_users')
                ->cascadeOnDelete();

            // Keys
            $table->string('public_key', 255);
            $table->string('preshared_key', 255)->nullable();
            $table->text('private_key_encrypted');

            // Networking
            $table->string('ip_address', 45);
            $table->string('allowed_ips', 255)->nullable();
            $table->string('dns', 255)->nullable();

            // State
            $table->boolean('revoked')->default(false);

            // Stats & handshake
            $table->timestamp('last_handshake_at')->nullable();
            $table->unsignedBigInteger('transfer_rx_bytes')->default(0);
            $table->unsignedBigInteger('transfer_tx_bytes')->default(0);

            $table->timestamps();

            // A user can only have 1 WG peer per server
            $table->unique(['vpn_server_id', 'vpn_user_id'], 'wg_peers_server_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireguard_peers');
    }
};
