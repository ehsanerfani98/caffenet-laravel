<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: settings first (defaults + adminPhones), then the RBAC
     * catalog/roles, then the admin users derived from them, then the demo
     * catalog. Everything is safe to run repeatedly (idempotent).
     */
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RoleSeeder::class,
            AdminUsersSeeder::class,
            CatalogSeeder::class,
            // Smart queue / capacity:
            OrderTypeSeeder::class,
        ]);
    }
}
