<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique();

            // Doctor who teaches the course
            $table->foreignId('doctor_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->string('semester')->nullable();
            $table->string('level')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
