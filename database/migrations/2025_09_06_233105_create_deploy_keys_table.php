<?php

// database/migrations/2025_09_06_000000_create_deploy_keys_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('deploy_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();                 // e.g. "default-ed25519"
            $table->string('private_path');                   // relative to storage/app/ssh_keys OR absolute path
            $table->longText('public_key');                   // full "ssh-ed25519 AAAA... panel@host"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Optional: set a default column on servers to reference the active deploy key later
        if (!Schema::hasColumn('vpn_servers','deploy_key_id')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                $table->foreignId('deploy_key_id')->nullable()->constrained('deploy_keys')->nullOnDelete();
            });
        }
    }

    public function down(): void {
        if (Schema::hasColumn('vpn_servers','deploy_key_id')) {
            Schema::table('vpn_servers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('deploy_key_id');
            });
        }
        Schema::dropIfExists('deploy_keys');
    }
};
