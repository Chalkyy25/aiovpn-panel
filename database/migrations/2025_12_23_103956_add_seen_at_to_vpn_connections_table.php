<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_user_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('vpn_user_connections', 'seen_at')) {
                $table->timestamp('seen_at')->nullable()->index()->after('connected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_user_connections', function (Blueprint $table) {
            if (Schema::hasColumn('vpn_user_connections', 'seen_at')) {
                $table->dropColumn('seen_at');
            }
        });
    }
};
