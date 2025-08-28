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
            // store total session duration in seconds (nullable until disconnect)
            $table->integer('session_duration')->nullable()->after('disconnected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_user_connections', function (Blueprint $table) {
            $table->dropColumn('session_duration');
        });
    }
};
