<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();

            $table->string('title', 255);
            // w kontrolerze 'description' jest nullable → kolumna też musi być nullable
            $table->text('description')->nullable();

            $table->string('file_path')->nullable();         // ścieżka do pliku w 'public'
            $table->boolean('is_private')->default(true);    // prywatna domyślnie

            // właściciel notatki
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // (NOWE) kurs, w którym notatka została udostępniona (opcjonalnie)
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            $table->timestamps();

            // pomocnicze indeksy pod typowe zapytania
            $table->index(['user_id', 'is_private']);
            $table->index(['course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
