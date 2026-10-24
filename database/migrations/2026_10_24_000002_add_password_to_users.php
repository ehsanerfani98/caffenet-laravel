<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «یک‌بار ورود» — persistent login support.
 *
 * The OTP login now runs Fortify's pipeline with remember=true, which queues
 * the remember_web_* cookie. This Laravel's SessionGuard::userFromRecaller()
 * additionally requires users to carry a STRING password (it re-hashes it
 * and compares against the hash embedded in the recaller cookie) — with a
 * NULL password the cookie is silently dead and users got logged out as soon
 * as their session expired. The users table never had a password column
 * (login is OTP-only), so add it; AuthService fills it with an unusable
 * random string at login time. Nothing authenticates with passwords here —
 * the value exists purely so Laravel's remember machinery can validate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'password')) {
                $table->dropColumn('password');
            }
        });
    }
};
