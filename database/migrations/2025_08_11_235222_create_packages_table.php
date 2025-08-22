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
Schema::create('packages', function (Blueprint $t) {
    $t->id();
    $t->string('name');
    $t->unsignedInteger('price_credits');   // cost to create a vpn user
    $t->unsignedTinyInteger('max_connections')->default(1);
    $t->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
