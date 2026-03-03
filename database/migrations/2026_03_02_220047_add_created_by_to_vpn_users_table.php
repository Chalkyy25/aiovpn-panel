<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('client_id')
                ->constrained('users')
                ->nullOnDelete()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
