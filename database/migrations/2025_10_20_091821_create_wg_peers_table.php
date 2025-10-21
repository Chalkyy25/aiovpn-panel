<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_wg_peers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wg_peers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('server_id')->constrained('vpn_servers')->cascadeOnDelete();
            $t->string('public_key', 64);
            $t->string('allowed_ip', 64);      // e.g. "10.66.66.10/32"
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->unique(['server_id','public_key']);
            $t->unique(['server_id','allowed_ip']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('wg_peers');
    }
};
