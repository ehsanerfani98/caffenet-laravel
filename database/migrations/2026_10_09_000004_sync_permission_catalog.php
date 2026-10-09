<?php

use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Guarantees the database has the FULL current permission catalog (including
 * `users.delete` and the `wallet.*` set) and re-syncs the built-in roles, so an
 * already-migrated installation never misses a key that the UI already offers.
 * Idempotent — safe to run on any installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionService::allKeys() as $key) {
            Permission::findOrCreate($key, $guard);
        }

        $admin = Role::findOrCreate('admin', $guard);
        $admin->syncPermissions(PermissionService::allKeys());

        $operator = Role::findOrCreate('operator', $guard);
        $operator->syncPermissions(
            PermissionService::expand(PermissionService::roleDefaults('operator'))
        );
    }

    public function down(): void
    {
        // No-op: this migration only ensures existing catalog keys/roles.
    }
};
