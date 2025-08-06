<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_user_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_user_id')->constrained()->onDelete('cascade');
            $table->foreignId('vpn_server_id')->constrained()->onDelete('cascade');
            $table->boolean('is_connected')->default(false);
            $table->string('client_ip')->nullable();
            $table->string('virtual_ip')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->bigInteger('bytes_received')->default(0);
            $table->bigInteger('bytes_sent')->default(0);
            $table->timestamps();

            // Ensure unique connection per user per server
            $table->unique(['vpn_user_id', 'vpn_server_id']);

            // Index for quick lookups
            $table->index(['vpn_server_id', 'is_connected']);
            $table->index(['vpn_user_id', 'is_connected']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_user_connections');
    }
};
