<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('attendance_session_id')
                ->constrained('attendance_sessions')
                ->onDelete('cascade');

            $table->enum('method', ['face', 'qr']);

            $table->enum('status', ['present', 'late', 'absent'])
                ->default('present');

            $table->decimal('match_score', 5, 2)->nullable();

            $table->timestamp('attended_at')->nullable();

            $table->timestamps();

            // Prevent duplicate attendance
            $table->unique(['user_id', 'attendance_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
