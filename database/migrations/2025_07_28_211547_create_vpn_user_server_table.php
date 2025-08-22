
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First check if the table has the old column names
        if (Schema::hasColumn('vpn_user_server', 'vpn_user_id')) {
            // Drop existing foreign keys
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->dropForeign(['vpn_user_id']);
                $table->dropForeign(['vpn_server_id']);
            });

            // Rename columns
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->renameColumn('vpn_user_id', 'user_id');
                $table->renameColumn('vpn_server_id', 'server_id');
            });

            // Add new foreign keys
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('vpn_users')->onDelete('cascade');
                $table->foreign('server_id')->references('id')->on('vpn_servers')->onDelete('cascade');
            });
        }

        // Add unique constraint if it doesn't exist
        Schema::table('vpn_user_server', function (Blueprint $table) {
            // Check if the unique constraint already exists
            $uniqueConstraints = DB::select("SHOW INDEXES FROM vpn_user_server WHERE Key_name = 'vpn_user_server_user_id_server_id_unique'");
            if (empty($uniqueConstraints)) {
                $table->unique(['user_id', 'server_id']);
            }
        });
    }

    public function down(): void
    {
        // If we need to revert, we would remove the unique constraint
        // and rename columns back to their original names
        Schema::table('vpn_user_server', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'server_id']);
        });

        // Only rename columns back if we're truly reverting to the original state
        if (Schema::hasColumn('vpn_user_server', 'user_id')) {
            // Drop existing foreign keys
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['server_id']);
            });

            // Rename columns back
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->renameColumn('user_id', 'vpn_user_id');
                $table->renameColumn('server_id', 'vpn_server_id');
            });

            // Add original foreign keys
            Schema::table('vpn_user_server', function (Blueprint $table) {
                $table->foreign('vpn_user_id')->references('id')->on('vpn_users')->onDelete('cascade');
                $table->foreign('vpn_server_id')->references('id')->on('vpn_servers')->onDelete('cascade');
            });
        }
    }
};
