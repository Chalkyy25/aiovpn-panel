<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->integer('max_connections')->default(1);
          //$table->boolean('is_online')->default(false);
            ////$table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropColumn(['max_connections', 'is_online', 'last_seen_at']);
        });
    }
};
