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

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('inviter_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Dane zaproszenia
            $table->string('invited_email', 255);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled', 'expired'])
                ->default('pending');

            $table->enum('role', ['owner', 'admin', 'moderator', 'user', 'member'])
                ->default('user');

            $table->string('token', 64)->unique();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // Indeksy pod najczęstsze zapytania
            $table->index(['course_id', 'invited_email']);
            $table->index(['status', 'expires_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
