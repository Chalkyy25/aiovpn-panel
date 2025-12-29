<?php

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

            $table->unsignedBigInteger('vpn_server_id');
            $table->unsignedBigInteger('vpn_user_id');

            $table->string('protocol', 20);
            $table->string('session_key', 191)->nullable();
            $table->string('wg_public_key', 64)->nullable();

            $table->string('client_ip', 128)->nullable();
            $table->string('virtual_ip', 128)->nullable();
            $table->string('endpoint', 128)->nullable();

            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes / uniques (match MySQL)
            $table->unique(['vpn_server_id', 'session_key'], 'vpn_connections_vpn_server_id_session_key_unique');
            $table->unique(['vpn_server_id', 'wg_public_key'], 'vpn_conn_server_wgkey_unique');

            $table->index(['vpn_user_id', 'is_active'], 'vpn_connections_vpn_user_id_is_active_index');
            $table->index(['vpn_server_id', 'is_active'], 'vpn_connections_vpn_server_id_is_active_index');
            $table->index(['vpn_server_id', 'last_seen_at'], 'vpn_connections_vpn_server_id_last_seen_at_index');
            $table->index(['protocol', 'is_active'], 'vpn_conn_protocol_active_idx');
            $table->index(['vpn_server_id', 'vpn_user_id', 'protocol', 'is_active'], 'vpn_conn_server_user_proto_active_idx');

            // FKs
            $table->foreign('vpn_server_id', 'vpn_connections_vpn_server_id_foreign')
                ->references('id')->on('vpn_servers')
                ->onDelete('cascade');

            $table->foreign('vpn_user_id', 'vpn_connections_vpn_user_id_foreign')
                ->references('id')->on('vpn_users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_connections');
    }
};
