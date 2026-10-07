<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the obsolete `otpTestMode` setting.
 *
 * The OTP code is an SMS, so when the SMS simulator (`smsTestMode`) is on every
 * message — including the OTP — is already simulated. The separate
 * `otpTestMode` flag was therefore redundant and has been dropped from
 * SettingsService::DEFS; this migration deletes any stored row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'otpTestMode')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'otpTestMode'], ['value' => '0']);
    }
};
