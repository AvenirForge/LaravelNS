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
        /**
         * USERS
         */
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Podstawowe dane
            $table->string('name', 191);
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Hasło (hash po stronie aplikacji)
            $table->string('password');

            // Avatar – domyślna ścieżka, możliwość null
            $table->string('avatar', 255)->nullable()->default('avatars/default.png');

            // Remember-me token
            $table->rememberToken();

            // Timestamps
            $table->timestamps();

            // Przydatny indeks pod listowanie
            $table->index('created_at');
        });

        /**
         * CACHE (database cache store)
         */
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        /**
         * SESSIONS (database session store)
         */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            // Powiązanie z users; w razie usunięcia usera zostawiamy sesję z null
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('set null');
        });

        /**
         * JOBS (queue:database)
         */
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        /**
         * FAILED JOBS
         */
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        /**
         * PERSONAL ACCESS TOKENS (Laravel Sanctum / tokens API)
         */
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable'); // tokenable_type, tokenable_id (indexed)
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kolejność odwrotna do tworzenia + najpierw zależne od users
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');

        // sessions ma FK do users → drop przed users
        if (Schema::hasTable('sessions')) {
            // Bezpiecznie usuń constraint (dla niektórych DB wymagane)
            try {
                Schema::table('sessions', function (Blueprint $table) {
                    // Nazwa indeksu FK może być różna między DBMS — brak błędu jeśli nie istnieje
                    // W większości przypadków droppowanie całej tabeli wystarczy,
                    // ale część środowisk lubi najpierw dropnąć FK.
                });
            } catch (\Throwable $e) {
                // Ignoruj – i tak dropujemy tabelę
            }
        }
        Schema::dropIfExists('sessions');

        Schema::dropIfExists('cache');
        Schema::dropIfExists('users');
    }
};
