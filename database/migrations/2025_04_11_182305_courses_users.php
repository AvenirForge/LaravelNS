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
            $table->foreignId('course_id')->constrained()->onDelete('cascade'); // ID kursu
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // ID użytkownika
            $table->enum('role', ['user', 'moderator', 'admin'])->default('user'); // Rola użytkownika
            $table->timestamps();

            $table->unique(['course_id', 'user_id']); // Unikalne połączenie kursu i użytkownika
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
