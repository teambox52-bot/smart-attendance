<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->onDelete('cascade');

            $table->foreignId('created_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->enum('method', ['face', 'qr', 'both'])->default('face');

            $table->enum('status', ['scheduled', 'open', 'closed'])->default('scheduled');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
