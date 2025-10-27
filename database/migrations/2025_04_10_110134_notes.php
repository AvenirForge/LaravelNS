<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();

            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->string('file_path')->nullable();
            $table->boolean('is_private')->default(true);

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'is_private']);
        });

        Schema::create('course_note', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete(); // Usunięcie kursu usuwa powiązanie

            $table->foreignId('note_id')
                ->constrained('notes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete(); // Usunięcie notatki usuwa powiązanie

            $table->unique(['course_id', 'note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_note');
        Schema::dropIfExists('notes');
    }
};
