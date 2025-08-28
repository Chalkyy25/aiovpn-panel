<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_user_connections', function (Blueprint $table) {
            $table->string('virtual_ip')->nullable()->after('client_ip');
            $table->bigInteger('bytes_received')->default(0)->after('virtual_ip');
            $table->bigInteger('bytes_sent')->default(0)->after('bytes_received');
            $table->timestamp('connected_at')->nullable()->change(); // ensure nullable
        });
    }

    public function down(): void
    {
        Schema::table('vpn_user_connections', function (Blueprint $table) {
            $table->dropColumn(['virtual_ip','bytes_received','bytes_sent']);
        });
    }
};
