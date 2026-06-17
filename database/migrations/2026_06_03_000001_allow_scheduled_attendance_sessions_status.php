<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendance_sessions MODIFY status ENUM('scheduled', 'open', 'closed') NOT NULL DEFAULT 'scheduled'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendance_sessions MODIFY status ENUM('open', 'closed') NOT NULL DEFAULT 'open'");
        }
    }
};
