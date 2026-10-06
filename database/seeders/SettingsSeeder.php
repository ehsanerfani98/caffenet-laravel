<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Settings seeder (repeatable/idempotent): creates a row for every
 * SYSTEM_SETTING default that does not exist yet — INSERT OR IGNORE semantics
 * of the original 001_settings_seeder. Never overwrites stored values.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        \App\Services\SettingsService::seedDefaults();
        // Boot-time guarantee from server.ts: the JWT secret is auto-generated
        // and persisted on first run so OTP hashing + sessions work at once.
        \App\Services\SettingsService::ensureJwtSecret();
    }
}
