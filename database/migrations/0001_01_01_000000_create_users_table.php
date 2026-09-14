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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            /*
             * The Firebase Auth UID, held as a VARCHAR and NOT as a native `uuid`
             * column.
             *
             * A Firebase UID is a 28-character base62 string, not an RFC-4122 UUID —
             * `$table->uuid()` would reject every real one. This column is the join
             * between a customer here and the same customer in Firestore's `users`
             * collection and in the mobile app, so the two have to agree exactly.
             *
             * Nullable because the row can exist before the Firebase account does:
             * an email/password registration is written here first and the UID is set
             * once Firebase returns it.
             */
            $table->string('uuid', 64)->nullable()->unique();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            /*
             * How this account signs in — see config/auth_providers.php. The admin
             * panel reads the same field off Firestore and prints it as
             * "Email & password" / "Google", so the stored values must match.
             */
            $table->string('provider', 32)->default('email')->index();

            /*
             * Nullable: a Google account has no password of ours to store, and a
             * NOT NULL column would force a meaningless hash to be invented for it.
             */
            $table->string('password')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
