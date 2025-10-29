<?php

// database/migrations/2025_10_29_120000_add_protocol_transport_to_vpn_servers.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        // Add columns only if they don't already exist
        if (!Schema::hasColumn('vpn_servers', 'protocol')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                $table->enum('protocol', ['openvpn', 'wireguard'])
                      ->default('openvpn')
                      ->after('ip_address');
            });
        }

        if (!Schema::hasColumn('vpn_servers', 'transport')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                $table->enum('transport', ['udp','tcp'])
                      ->nullable()
                      ->after('protocol'); // harmless even if protocol already exists
            });
        }

        // Optional index (guarded)
        if (!Schema::hasColumn('vpn_servers', 'protocol') || !Schema::hasColumn('vpn_servers', 'transport')) {
            // skip creating a combined index until both exist
        } else {
            Schema::table('vpn_servers', function (Blueprint $table) {
                // Laravel <11 doesn't have hasIndex(); duplicate creation will error, so
                // keep it simple: create a single-column index that’s safe to re-run.
                $table->index('protocol', 'vpn_servers_protocol_idx');
            });
        }
    }

    public function down(): void
    {
        // Drop safely if present
        if (Schema::hasColumn('vpn_servers', 'transport')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                $table->dropColumn('transport');
            });
        }
        if (Schema::hasColumn('vpn_servers', 'protocol')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                // also drop index if you created it
                if (collect(Schema::getColumnListing('vpn_servers'))->contains('protocol')) {
                    try { $table->dropIndex('vpn_servers_protocol_idx'); } catch (\Throwable $e) {}
                }
                $table->dropColumn('protocol');
            });
        }
    }
};
