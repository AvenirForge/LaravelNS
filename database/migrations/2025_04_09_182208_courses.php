<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────────────────
        // Tabela: courses
        // ──────────────────────────────────────────────────────────────────────────
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            // Twórca kursu (owner w sensie "creator"; dodatkowo będzie pivot z role=owner)
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();

            // Zgodnie z kontrolerem: public | private | 100% private
            // Uwaga: enum jest OK na MySQL/MariaDB; dla innych silników można użyć string + CHECK (patrz komentarz na dole).
            $table->enum('type', ['public', 'private', '100% private'])->default('private');

            // Relatywna ścieżka względem storage/app/public, np. "courses/avatars/abc.jpg"
            $table->string('avatar')->nullable();

            $table->timestamps();

            // Indeksy pomocnicze pod typowe zapytania
            $table->index(['user_id', 'type']);

            // Opcjonalnie: komentarze tabeli/kolumn (MySQL)
            // $table->comment('Courses created by users');
        });

        // ──────────────────────────────────────────────────────────────────────────
        // Tabela: courses_users (pivot z rolą i statusem zaproszenia/członkostwa)
        // ──────────────────────────────────────────────────────────────────────────
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

            // Rola używana w kontrolerze do autoryzacji
            $table->enum('role', ['owner','admin','moderator','member','user'])->default('member');

            // Status zaproszenia/członkostwa
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('accepted');

            $table->timestamps();

            // Unikalność przypisania użytkownika do kursu
            $table->unique(['course_id', 'user_id']);

            // Indeksy pod częste zapytania
            $table->index(['course_id', 'status']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        // Najpierw pivot (ma FK do courses)
        Schema::dropIfExists('courses_users');
        Schema::dropIfExists('courses');
    }
};

