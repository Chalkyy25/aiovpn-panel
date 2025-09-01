<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('packages', function (Blueprint $table) {
        $table->text('description')->nullable()->after('name');
        $table->unsignedInteger('duration_months')->default(1)->after('max_connections');
        $table->boolean('is_featured')->default(false)->after('duration_months');
        $table->boolean('is_active')->default(true)->after('is_featured');
    });
}

public function down()
{
    Schema::table('packages', function (Blueprint $table) {
        $table->dropColumn(['description', 'duration_months', 'is_featured', 'is_active']);
    });
}
};
