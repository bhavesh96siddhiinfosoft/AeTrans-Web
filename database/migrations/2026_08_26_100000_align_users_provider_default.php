<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `users.provider` default said `email`; nothing means `email` any more.
 *
 * The column holds FIREBASE'S own provider ids — `password`, `google.com`, `apple.com`,
 * `phone` — because the admin panel reads the same field off Firestore and keys its
 * labels on those exact strings (see config/auth_providers.php). The default was written
 * before that was settled and was never updated, so a row created without an explicit
 * provider got a value neither side can label.
 *
 * No existing row is touched: every account the site has created went through
 * `CustomerAccounts`, which always sets the provider explicitly. This only corrects what
 * a row would get in its absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider', 32)->default('password')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider', 32)->default('email')->change();
        });
    }
};
