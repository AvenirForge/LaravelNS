<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->enum('status', ['private', 'public', 'archived'])->default('private');

            $table->timestamps();

            $table->unique(['user_id', 'title']);

            $table->index(['user_id', 'status']);
        });

        Schema::create('tests_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->foreignId('test_id')->constrained('tests')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('tests_answers', function (Blueprint $table) {
            $table->id();
            $table->text('answer');
            $table->boolean('is_correct')->default(false);
            $table->foreignId('question_id')->constrained('tests_questions')->onDelete('cascade');
            $table->timestamps();

            $table->index(['question_id', 'is_correct']);
        });

        Schema::create('course_test', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('test_id')
                ->constrained('tests')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unique(['course_id', 'test_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_test');
        Schema::dropIfExists('tests_answers');
        Schema::dropIfExists('tests_questions');
        Schema::dropIfExists('tests');
    }
};
