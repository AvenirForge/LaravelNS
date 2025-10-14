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

            // Hasło (hash po stronie aplikacji; w modelu masz casts['password' => 'hashed'])
            $table->string('password', 255);

            // Avatar – domyślna ścieżka zgodna z User::DEFAULT_AVATAR_RELATIVE
            $table->string('avatar', 255)->nullable()->default('users/avatars/default.png');

            // Remember-me token
            $table->rememberToken();

            // Timestamps
            $table->timestamps();

            // Przydatny indeks pod listowanie
            $table->index('created_at');
        });

        /**
         * PASSWORD RESET TOKENS (standard Laravel)
         */
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token', 255);
            $table->timestamp('created_at')->nullable();
        });

        /**
         * CACHE (database cache store — nie przeszkadza, nawet jeśli używasz file)
         */
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        /**
         * SESSIONS (database session store — kompatybilne z defaultem Laravela)
         * FK do users — przy kasowaniu usera sesja zostaje z user_id=NULL.
         */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('set null');
        });

        /**
         * JOBS (queue:database)
         */
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue', 191)->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
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
         * PERSONAL ACCESS TOKENS (neutralne dla JWT; nie używane = nie przeszkadzają)
         */
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable'); // tokenable_type, tokenable_id
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
        Schema::dropIfExists('sessions');

        Schema::dropIfExists('cache');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
