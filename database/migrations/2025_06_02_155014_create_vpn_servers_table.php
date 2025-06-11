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
Schema::create('vpn_servers', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('ip');
    $table->integer('ssh_port')->default(22);
    $table->string('ssh_user')->default('root');
    $table->string('protocol')->default('openvpn');
    $table->string('location')->nullable();
    $table->string('group')->nullable();
    $table->string('status')->default('pending'); // optional if you track up/down status
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_servers');
    }
};
