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
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');  // Powiązanie z kursem
            $table->foreignId('inviter_id')->constrained('users')->onDelete('cascade');  // Powiązanie z zapraszającym
            $table->string('invited_email');  // E-mail zaproszonego użytkownika
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');  // Status zaproszenia
            $table->string('token')->unique();  // Unikalny token do zaproszenia
            $table->timestamp('expires_at');  // Czas wygaśnięcia zaproszenia
            $table->timestamp('responded_at')->nullable();  // Czas odpowiedzi na zaproszenie (jeśli użytkownik zaakceptował lub odrzucił)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');  // Powiązanie z użytkownikiem, który zaakceptował zaproszenie (jeśli dotyczy)

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
