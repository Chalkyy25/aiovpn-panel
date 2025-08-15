<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_servers', 'provider')) {
                $table->string('provider')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'region')) {
                $table->string('region')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'country_code')) {
                $table->string('country_code', 2)->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'city')) {
                $table->string('city', 80)->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'tags')) {
                $table->json('tags')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
            // ❌ Skip ssh_port because it already exists
            if (!Schema::hasColumn('vpn_servers', 'ipv6_enabled')) {
                $table->boolean('ipv6_enabled')->default(false);
            }
            if (!Schema::hasColumn('vpn_servers', 'mtu')) {
                $table->unsignedSmallInteger('mtu')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'api_endpoint')) {
                $table->string('api_endpoint')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'api_token')) {
                $table->string('api_token')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'monitoring_enabled')) {
                $table->boolean('monitoring_enabled')->default(true);
            }
            if (!Schema::hasColumn('vpn_servers', 'health_check_cmd')) {
                $table->string('health_check_cmd')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'install_branch')) {
                $table->string('install_branch')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'max_clients')) {
                $table->unsignedSmallInteger('max_clients')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'rate_limit_mbps')) {
                $table->unsignedSmallInteger('rate_limit_mbps')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'allow_split_tunnel')) {
                $table->boolean('allow_split_tunnel')->default(false);
            }
            if (!Schema::hasColumn('vpn_servers', 'ovpn_cipher')) {
                $table->string('ovpn_cipher')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'ovpn_compression')) {
                $table->string('ovpn_compression')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'wg_public_key')) {
                $table->text('wg_public_key')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'wg_private_key')) {
                $table->text('wg_private_key')->nullable();
            }
            if (!Schema::hasColumn('vpn_servers', 'notes')) {
                $table->string('notes', 500)->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn([
                'provider', 'region', 'country_code', 'city', 'tags',
                'enabled', 'ipv6_enabled', 'mtu', 'api_endpoint', 'api_token',
                'monitoring_enabled', 'health_check_cmd', 'install_branch',
                'max_clients', 'rate_limit_mbps', 'allow_split_tunnel',
                'ovpn_cipher', 'ovpn_compression', 'wg_public_key',
                'wg_private_key', 'notes'
            ]);
        });
    }
};
