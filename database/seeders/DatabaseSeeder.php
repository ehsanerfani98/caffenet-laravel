<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: settings first (defaults + adminPhones), then the admin
     * users derived from them, then the demo catalog. Settings/AdminUsers are
     * safe to run repeatedly (idempotent).
     */
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            AdminUsersSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
