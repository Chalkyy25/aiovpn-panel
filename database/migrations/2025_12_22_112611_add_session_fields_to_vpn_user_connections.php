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
    Schema::table('vpn_user_connections', function (Blueprint $table) {
        $table->string('session_key', 120)->nullable()->index();
        $table->unsignedInteger('client_id')->nullable()->index();
        $table->unsignedSmallInteger('mgmt_port')->nullable()->index();
        $table->string('public_key', 80)->nullable()->index();

        // One active row per server+session identity
        $table->unique(['vpn_server_id', 'session_key'], 'vpn_conn_server_session_unique');
    });
}

public function down(): void
{
    Schema::table('vpn_user_connections', function (Blueprint $table) {
        $table->dropUnique('vpn_conn_server_session_unique');
        $table->dropColumn(['session_key', 'client_id', 'mgmt_port', 'public_key']);
    });
}

};
