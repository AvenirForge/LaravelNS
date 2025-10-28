<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates notes, course_note pivot, and note_files tables.
     */
    public function up(): void
    {
        // 1. Tabela notes (bez file_path)
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            // $table->string('file_path')->nullable(); // USUNIĘTO
            $table->boolean('is_private')->default(true);
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_private']);
        });

        // 2. Tabela pivot course_note (bez zmian)
        Schema::create('course_note', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('note_id')
                ->constrained('notes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unique(['course_id', 'note_id']);
            // Opcjonalnie: dodaj timestamps, jeśli chcesz śledzić, kiedy notatka została dodana do kursu
            // $table->timestamps();
        });

        // 3. NOWA TABELA: note_files
        Schema::create('note_files', function (Blueprint $table) {
            $table->id();
            // Klucz obcy do tabeli notes, z usunięciem kaskadowym rekordów plików
            // gdy notatka zostanie usunięta
            $table->foreignId('note_id')->constrained('notes')->onDelete('cascade');
            $table->string('file_path'); // Ścieżka do zapisanego pliku w storage
            $table->string('original_name')->nullable(); // Oryginalna nazwa pliku
            $table->string('mime_type')->nullable(); // Typ MIME
            $table->unsignedInteger('order')->default(0)->index(); // Kolejność wyświetlania
            // Można dodać inne pola jak 'caption', 'size' etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * Usuwa tabele w odwrotnej kolejności tworzenia.
     */
    public function down(): void
    {
        Schema::dropIfExists('note_files'); // Najpierw usuń tabelę z kluczem obcym
        Schema::dropIfExists('course_note');
        Schema::dropIfExists('notes');
    }
};
