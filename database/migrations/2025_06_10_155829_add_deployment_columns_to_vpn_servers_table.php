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
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->enum('deployment_status', ['queued', 'running', 'success', 'failed'])->default('queued');
        $table->text('deployment_log')->nullable();
    });
}

public function down()
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->dropColumn(['deployment_status', 'deployment_log']);
    });
}

};
