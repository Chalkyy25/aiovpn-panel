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
        Schema::create('kick_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('kicked_by');
            $table->timestamp('kicked_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('vpn_users')->onDelete('cascade');
            $table->foreign('kicked_by')->references('id')->on('users')->onDelete('cascade');
            
            $table->index('user_id');
            $table->index('kicked_by');
            $table->index('kicked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kick_history');
    }
};