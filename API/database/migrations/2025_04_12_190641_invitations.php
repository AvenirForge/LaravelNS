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

            // Kurs, do którego dotyczy zaproszenie
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Użytkownik zapraszający (owner / moderator kursu)
            $table->foreignId('inviter_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Użytkownik, który przyjął/odrzucił zaproszenie (może być null dopóki pending)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Dane zaproszenia
            $table->string('invited_email', 255);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled', 'expired'])
                ->default('pending');

            // Rola, jaką zapraszany użytkownik otrzyma po akceptacji
            $table->enum('role', ['owner', 'admin', 'moderator', 'user', 'member'])
                ->default('user');

            // Token weryfikacyjny (np. do linków)
            $table->string('token', 64)->unique();

            // Daty ważności i odpowiedzi
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // Indeksy pod najczęstsze zapytania
            $table->index(['course_id', 'invited_email']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
