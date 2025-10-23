<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tests
        Schema::create('tests', function (Blueprint $table) {
            $table->id();

            // Autor testu
            $table->foreignId('user_id')
                ->constrained('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            // Kurs (opcjonalnie) — test udostępniony w kursie
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            // Dane testu
            $table->string('title', 255);
            $table->text('description')->nullable();

            // Status cyklu życia testu (spójny z API i testami E2E)
            $table->enum('status', ['private', 'public', 'archived'])->default('private');

            $table->timestamps();

            // Unikalny tytuł w ramach jednego autora
            $table->unique(['user_id', 'title']);

            // Indeksy pod typowe zapytania
            $table->index(['user_id', 'status']);
            $table->index(['course_id']);
        });

        // tests_questions
        Schema::create('tests_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->foreignId('test_id')->constrained('tests')->onDelete('cascade');
            $table->timestamps();
        });

        // tests_answers
        Schema::create('tests_answers', function (Blueprint $table) {
            $table->id();
            $table->text('answer');
            $table->boolean('is_correct')->default(false);
            $table->foreignId('question_id')->constrained('tests_questions')->onDelete('cascade');
            $table->timestamps();

            $table->index(['question_id', 'is_correct']);
        });

        // (Opcjonalnie) jeśli istnieje stary pivot 'course_test', nie tworzymy go — przestawiliśmy się na tests.course_id
    }

    public function down(): void
    {
        Schema::dropIfExists('tests_answers');
        Schema::dropIfExists('tests_questions');
        Schema::dropIfExists('tests');
    }
};
