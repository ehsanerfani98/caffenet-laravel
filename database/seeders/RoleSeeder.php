<?php

namespace Database\Seeders;

use App\Services\PermissionService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & permissions seeder (repeatable/idempotent) — spatie edition.
 *
 * The full catalog (resource.action keys from PermissionService) is synced
 * into spatie's permissions table with guard "web", then attached to roles:
 *
 *   admin    → every key, umbrella keys included (plus the Gate::before
 *              bypass in AppServiceProvider, so admins are never locked out
 *              of abilities added later);
 *   operator → the operator defaults EXPANDED into concrete permissions
 *              (dashboard.view + all orders.* + sms.view);
 *   customer → no management permissions.
 *
 * Server-side checks are plain spatie hasAnyPermission calls on exact names;
 * the manage/implicit-view semantics live in the expansion step, not at
 * check time.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset spatie's permission cache so repeated seeds stay consistent.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        foreach (PermissionService::allKeys() as $key) {
            Permission::findOrCreate($key, $guard);
        }

        $admin = Role::findOrCreate('admin', $guard);
        $admin->syncPermissions(PermissionService::allKeys());

        $operator = Role::findOrCreate('operator', $guard);
        $operator->syncPermissions(
            PermissionService::expand(PermissionService::roleDefaults('operator'))
        );

        Role::findOrCreate('customer', $guard);
    }
}
