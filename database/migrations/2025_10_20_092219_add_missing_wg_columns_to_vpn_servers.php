<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $t) {
            // Add only if missing
            if (!Schema::hasColumn('vpn_servers', 'wg_endpoint_host')) {
                $t->string('wg_endpoint_host', 255)->nullable()->after('wg_public_key');
            }
            if (!Schema::hasColumn('vpn_servers', 'wg_port')) {
                // Default to the port you actually use on the boxes (51820 in your script)
                $t->unsignedInteger('wg_port')->nullable()->default(51820)->after('wg_endpoint_host');
            }
            if (!Schema::hasColumn('vpn_servers', 'wg_subnet')) {
                $t->string('wg_subnet', 32)->nullable()->default('10.66.66.0/24')->after('wg_port');
            }
        });

        // (Optional) tighten types for keys if you want
        // Only do this if you're happy moving from TEXT -> VARCHAR
        // Schema::table('vpn_servers', function (Blueprint $t) {
        //     // MySQL: you can use ->change() if doctrine/dbal is installed
        //     // composer require doctrine/dbal
        //     // $t->string('wg_public_key', 64)->nullable()->change();
        //     // $t->text('wg_private_key')->nullable()->change(); // or keep as text
        // });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $t) {
            if (Schema::hasColumn('vpn_servers', 'wg_subnet')) $t->dropColumn('wg_subnet');
            if (Schema::hasColumn('vpn_servers', 'wg_port')) $t->dropColumn('wg_port');
            if (Schema::hasColumn('vpn_servers', 'wg_endpoint_host')) $t->dropColumn('wg_endpoint_host');
        });
    }
};
