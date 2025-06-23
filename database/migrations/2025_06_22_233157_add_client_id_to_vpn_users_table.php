<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up()
{
    Schema::table('vpn_users', function (Blueprint $table) {
        $table->unsignedBigInteger('client_id')->nullable()->after('id');
        // Add a foreign key if you want
        // $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');
    });
}

public function down()
{
    Schema::table('vpn_users', function (Blueprint $table) {
        $table->dropColumn('client_id');
    });
}

};
