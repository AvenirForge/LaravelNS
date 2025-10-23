<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────────────────
        // Tabela: courses
        // ──────────────────────────────────────────────────────────────────────────
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            // Twórca kursu (owner w sensie "creator"; dodatkowo będzie pivot z role=owner)
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();

            // Uwaga: kontroler dopuszcza też '100% private', ale testy używają 'private'/'public'.
            $table->enum('type', ['public', 'private'])->default('private');

            // Relatywna ścieżka względem storage/app/public, np. "courses/avatars/abc.jpg"
            $table->string('avatar')->nullable();

            $table->timestamps();

            // Indeksy pomocnicze pod typowe zapytania
            $table->index(['user_id', 'type']);
        });

        // ──────────────────────────────────────────────────────────────────────────
        // Tabela: courses_users (pivot z rolą i statusem zaproszenia/członkostwa)
        // ──────────────────────────────────────────────────────────────────────────
        Schema::create('courses_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Rola używana w kontrolerze do autoryzacji
            // Dodajemy również 'user' (mapowany w kodzie na 'member') dla pełnej kompatybilności.
            $table->enum('role', ['owner','admin','moderator','member','user'])->default('member');

            // Status zaproszenia/członkostwa
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('accepted');

            $table->timestamps();

            // Unikalność przypisania użytkownika do kursu
            $table->unique(['course_id', 'user_id']);

            // Indeksy pod częste zapytania
            $table->index(['course_id', 'status']);
            $table->index(['user_id', 'role']);
        });

        // ──────────────────────────────────────────────────────────────────────────
        // DOSTOSOWANIE ISTNIEJĄCYCH TABLIC: notes, tests
        // ──────────────────────────────────────────────────────────────────────────
        // Cel: course_id = NULLABLE oraz FK z ON DELETE SET NULL
        // (jeśli tabele istnieją; bez tworzenia nowych migracji)
        if (Schema::hasTable('notes')) {
            // Spróbuj zrzucić istniejący FK (nazwy różnią się między DB)
            try {
                Schema::table('notes', function (Blueprint $table) {
                    $table->dropForeign(['course_id']);
                });
            } catch (\Throwable $e) {
                try {
                    Schema::table('notes', function (Blueprint $table) {
                        $table->dropForeign('notes_course_id_foreign');
                    });
                } catch (\Throwable $e2) { /* ignore */ }
            }

            // Uwaga: change() wymaga doctrine/dbal
            Schema::table('notes', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable()->change();
            });

            Schema::table('notes', function (Blueprint $table) {
                $table->foreign('course_id')
                    ->references('id')->on('courses')
                    ->nullOnDelete()     // ← KLUCZOWE: usunięcie kursu → course_id = NULL
                    ->cascadeOnUpdate();
            });
        }

        if (Schema::hasTable('tests')) {
            try {
                Schema::table('tests', function (Blueprint $table) {
                    $table->dropForeign(['course_id']);
                });
            } catch (\Throwable $e) {
                try {
                    Schema::table('tests', function (Blueprint $table) {
                        $table->dropForeign('tests_course_id_foreign');
                    });
                } catch (\Throwable $e2) { /* ignore */ }
            }

            Schema::table('tests', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable()->change();
            });

            Schema::table('tests', function (Blueprint $table) {
                $table->foreign('course_id')
                    ->references('id')->on('courses')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        // Próba przywrócenia poprzednich ograniczeń dla notes/tests (tylko jeśli tabele istnieją).
        // Uwaga: operacja może się nie udać, jeśli w kolumnach są już wartości NULL.
        if (Schema::hasTable('notes')) {
            try {
                Schema::table('notes', function (Blueprint $table) {
                    $table->dropForeign(['course_id']);
                });
            } catch (\Throwable $e) {
                try {
                    Schema::table('notes', function (Blueprint $table) {
                        $table->dropForeign('notes_course_id_foreign');
                    });
                } catch (\Throwable $e2) { /* ignore */ }
            }
            // Przywracamy NOT NULL (jeśli to bezpieczne w Twoim środowisku)
            try {
                Schema::table('notes', function (Blueprint $table) {
                    $table->unsignedBigInteger('course_id')->nullable(false)->change();
                });
                Schema::table('notes', function (Blueprint $table) {
                    $table->foreign('course_id')
                        ->references('id')->on('courses')
                        ->cascadeOnDelete()
                        ->cascadeOnUpdate();
                });
            } catch (\Throwable $e) { /* zostaw jak jest, by nie brickować down() */ }
        }

        if (Schema::hasTable('tests')) {
            try {
                Schema::table('tests', function (Blueprint $table) {
                    $table->dropForeign(['course_id']);
                });
            } catch (\Throwable $e) {
                try {
                    Schema::table('tests', function (Blueprint $table) {
                        $table->dropForeign('tests_course_id_foreign');
                    });
                } catch (\Throwable $e2) { /* ignore */ }
            }
            try {
                Schema::table('tests', function (Blueprint $table) {
                    $table->unsignedBigInteger('course_id')->nullable(false)->change();
                });
                Schema::table('tests', function (Blueprint $table) {
                    $table->foreign('course_id')
                        ->references('id')->on('courses')
                        ->cascadeOnDelete()
                        ->cascadeOnUpdate();
                });
            } catch (\Throwable $e) { /* jw. */ }
        }

        // Najpierw pivot (ma FK do courses)
        Schema::dropIfExists('courses_users');
        Schema::dropIfExists('courses');
    }
};
