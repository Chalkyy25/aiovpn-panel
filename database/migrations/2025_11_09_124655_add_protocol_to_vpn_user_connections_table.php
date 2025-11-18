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
    Schema::table('vpn_user_connections', function (Blueprint $table) {
        if (!Schema::hasColumn('vpn_user_connections', 'protocol')) {
            $table->string('protocol', 16)->nullable()->after('virtual_ip');
        }
    });
}

public function down(): void
{
    Schema::table('vpn_user_connections', function (Blueprint $table) {
        if (Schema::hasColumn('vpn_user_connections', 'protocol')) {
            $table->dropColumn('protocol');
        }
    });
}
};
