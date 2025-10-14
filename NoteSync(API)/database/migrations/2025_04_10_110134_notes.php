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
            $table->string('title');
            $table->text('description');
            $table->string('file_path')->nullable(); // Ścieżka do pliku, np. PDF, Excel, zdjęcia
            $table->boolean('is_private')->default(true); // Prywatna/publiczna notka
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Klucz obcy do użytkownika
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
