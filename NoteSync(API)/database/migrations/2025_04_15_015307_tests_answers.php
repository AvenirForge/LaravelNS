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
        Schema::create('tests_answers', function (Blueprint $table) {
            $table->id();
            $table->text('answer');
            $table->boolean('is_correct')->default(false); // czy odpowiedź jest poprawna
            $table->foreignId('question_id')->constrained('tests_questions')->onDelete('cascade'); // powiązanie z pytaniem
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
