<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up()
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->integer('port')->nullable();
        $table->string('transport')->nullable();
        $table->string('dns')->nullable();
        $table->boolean('enable_ipv6')->default(false);
        $table->boolean('enable_logging')->default(false);
        $table->boolean('enable_proxy')->default(false);
        $table->boolean('header1')->default(false);
        $table->boolean('header2')->default(false);
    });
}

public function down()
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->dropColumn([
            'port',
            'transport',
            'dns',
            'enable_ipv6',
            'enable_logging',
            'enable_proxy',
            'header1',
            'header2',
        ]);
    });
}
};
