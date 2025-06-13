<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('vpn_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address'); // NOT $table->string('ip');
            $table->string('ssh_user')->default('root');
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_type')->nullable();         // 'key' or 'password'
            $table->text('ssh_key')->nullable();            // For storing private key (if needed)
            $table->text('ssh_password')->nullable();       // For storing password (if needed)
            $table->string('protocol')->default('openvpn'); // 'openvpn' or 'wireguard'
            $table->string('port')->nullable();
            $table->string('transport')->nullable();        // 'udp', 'tcp', etc.
            $table->string('dns')->nullable();
            $table->string('location')->nullable();
            $table->string('group')->nullable();
            $table->boolean('enable_ipv6')->default(false);
            $table->boolean('enable_logging')->default(false);
            $table->boolean('enable_proxy')->default(false);
            $table->boolean('header1')->default(false);
            $table->boolean('header2')->default(false);
            $table->enum('status', ['pending','active','inactive'])->default('pending');
            $table->enum('deployment_status', ['queued','running','success','failed','pending','deployed'])->default('queued');
            $table->text('deployment_log')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('vpn_servers');
    }
};
