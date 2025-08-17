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
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->string('ssh_username')->nullable();
        $table->string('ssh_key_path')->nullable();
        $table->enum('ssh_login_type', ['key', 'password'])->nullable();
        $table->boolean('ssh_password_set')->default(false);
        $table->text('ssh_password')->nullable(); // store encrypted if needed
        $table->integer('ssh_port')->default(22);
    });
}

public function down(): void
{
    Schema::table('vpn_servers', function (Blueprint $table) {
        $table->dropColumn([
            'ssh_username',
            'ssh_key_path',
            'ssh_login_type',
            'ssh_password_set',
            'ssh_password',
            'ssh_port',
        ]);
    });
}

};
