<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('is_active')->default(true)->after('password');
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('id');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('is_active');
        $table->dropForeign(['created_by']);
        $table->dropColumn('created_by');
    });
}
};
