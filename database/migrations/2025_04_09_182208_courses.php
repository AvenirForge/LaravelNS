<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('type', ['public', 'private'])->default('private');
            $table->string('avatar')->nullable();

            $table->timestamps();

            // Indeksy pomocnicze pod typowe zapytania
            $table->index(['user_id', 'type']);
        });
        Schema::create('courses_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->enum('role', ['owner','admin','moderator','member','user'])->default('member');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('accepted');
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);

            $table->index(['course_id', 'status']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses_users');
        Schema::dropIfExists('courses');
    }
};
