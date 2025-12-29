<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

public function up(): void
{
    // Columns already exist on production. No-op.
}

public function down(): void
{
    // No-op.
}
};
