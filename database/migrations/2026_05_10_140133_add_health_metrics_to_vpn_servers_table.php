<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {

            $table->decimal('cpu_usage', 5, 2)
                ->nullable()
                ->after('online_users');

            $table->decimal('memory_usage', 5, 2)
                ->nullable()
                ->after('cpu_usage');

            $table->decimal('load_average', 5, 2)
                ->nullable()
                ->after('memory_usage');

        });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {

            $table->dropColumn([
                'cpu_usage',
                'memory_usage',
                'load_average',
            ]);

        });
    }
};
