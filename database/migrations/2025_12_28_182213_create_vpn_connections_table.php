<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_connections', function (Blueprint $table) {
            $table->id();

            // Which VPN node
            $table->foreignId('vpn_server_id')
                ->constrained('vpn_servers')
                ->cascadeOnDelete();

            // Which VPN line/account (NOT users table)
            $table->foreignId('vpn_user_id')
                ->constrained('vpn_users')
                ->cascadeOnDelete();

            // openvpn | wireguard
            $table->string('protocol', 20);

            /**
             * A stable unique key for the session on that server.
             * - OpenVPN: build from mgmt fields (username + real address + connected_at) and hash it
             * - WireGuard: use public_key (or a hash of it) + server_id
             */
            $table->string('session_key', 191);

            // Network info (optional but useful)
            $table->string('client_ip', 64)->nullable();     // internet client ip
            $table->string('virtual_ip', 64)->nullable();    // ovpn virtual ip or wg allowed-ip ip part
            $table->string('endpoint', 64)->nullable();      // wg endpoint ip:port if known

            // Traffic counters
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);

            // Lifecycle
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            // Quick active flag
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Prevent dup sessions per server
            $table->unique(['vpn_server_id', 'session_key']);

            // Query speed for dashboards
            $table->index(['vpn_user_id', 'is_active']);
            $table->index(['vpn_server_id', 'is_active']);
            $table->index(['vpn_server_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_connections');
    }
};
