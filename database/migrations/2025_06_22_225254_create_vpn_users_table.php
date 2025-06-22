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
    	Schema::create('vpn_users', function (Blueprint $table) {
       	 $table->id();
       	 $table->unsignedBigInteger('vpn_server_id');
       	 $table->string('username')->unique();
       	 $table->string('password');
       	 $table->timestamps();

        $table->foreign('vpn_server_id')->references('id')->on('vpn_servers')->onDelete('cascade');
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_users');
    }
};
