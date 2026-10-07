<?php

use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the `sms.delete` permission added to the catalog alongside the
 * SMS-log delete / clear-all feature, then re-syncs the built-in roles so an
 * already-migrated installation gains the key without a fresh seed.
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', 'sms.delete')
            ->where('guard_name', 'web')
            ->delete();
    }
};
