<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['vpn_server_id']);

            // Now drop the column
            $table->dropColumn('vpn_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->foreignId('vpn_server_id')->nullable()->constrained('vpn_servers');
        });
    }
};
