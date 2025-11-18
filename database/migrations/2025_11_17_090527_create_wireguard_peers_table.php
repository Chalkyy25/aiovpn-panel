<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wireguard_peers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vpn_server_id')
                ->constrained('vpn_servers')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('public_key', 255);
            $table->string('preshared_key', 255)->nullable();

            // Encrypted private key
            $table->text('private_key_encrypted');

            // Assigned IP inside wg_subnet, e.g. 10.66.66.10
            $table->string('ip_address', 45);

            // Usually "0.0.0.0/0, ::/0"
            $table->string('allowed_ips', 255)->nullable();

            // Optional peer-level DNS override
            $table->string('dns', 255)->nullable();

            $table->boolean('revoked')->default(false);

            // Optional stats
            $table->timestamp('last_handshake_at')->nullable();
            $table->unsignedBigInteger('transfer_rx_bytes')->default(0);
            $table->unsignedBigInteger('transfer_tx_bytes')->default(0);

            $table->timestamps();

            $table->unique(['vpn_server_id', 'user_id'], 'wg_peers_server_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireguard_peers');
    }
};
