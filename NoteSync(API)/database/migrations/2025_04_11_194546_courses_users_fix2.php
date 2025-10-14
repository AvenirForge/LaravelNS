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
        Schema::create('course_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')
                ->onDelete('cascade') // Jeśli kurs zostanie usunięty, usuwamy powiązane rekordy
                ->onUpdate('cascade'); // Jeśli kurs zostanie zaktualizowany, zaktualizujemy relację
            $table->foreignId('user_id')->constrained('users')
                ->onDelete('cascade') // Jeśli użytkownik zostanie usunięty, usuwamy powiązane rekordy
                ->onUpdate('cascade'); // Jeśli użytkownik zostanie zaktualizowany, zaktualizujemy relację
            $table->enum('role', ['user', 'moderator', 'admin', 'owner'])->default('user');
            $table->timestamps();

            // Indeksy dla kluczy obcych
            $table->index(['course_id', 'user_id']);
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
