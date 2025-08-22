<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            //$table->string('ssh_type')->nullable();
           // $table->string('ssh_password')->nullable();
        });
    }

    public function down()
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn(['ssh_type', 'ssh_password']);
        });
    }
};
