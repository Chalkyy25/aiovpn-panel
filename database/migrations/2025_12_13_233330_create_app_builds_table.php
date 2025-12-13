<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_builds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version_code');
            $table->string('version_name');
            $table->string('apk_path');
            $table->string('sha256', 64);
            $table->boolean('mandatory')->default(false);
            $table->text('release_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_builds');
    }
};
