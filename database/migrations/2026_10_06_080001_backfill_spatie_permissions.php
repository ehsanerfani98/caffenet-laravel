<?php

use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migrates the legacy permission model (users.role + users.permissions_json
 * JSON override) onto spatie/laravel-permission, then drops the JSON column
 * and the obsolete JWT signing settings.
 *
 * Mapping rules (identical to User::syncAccess):
 *   - admin role users            → spatie role "admin" (full catalog)
 *   - non-admin without override  → spatie role matching users.role
 *   - non-admin with override     → spatie role "customer" (zero defaults)
 *                                   + expanded override as direct permissions
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        // 1. Catalog + roles (mirrors RoleSeeder so plain `migrate` without
        //    `--seed` still yields a consistent state).
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
        Role::findOrCreate('customer', $guard);

        // 2. Backfill every user.
        User::query()->chunk(200, function ($users) {
            foreach ($users as $user) {
                $role = $user->role ?: 'customer';
                $user->syncRoles([$role === 'admin' ? 'admin' : $role]);

                $override = $role === 'admin'
                    ? null
                    : PermissionService::normalize($user->getRawOriginal('permissions_json'));

                if ($override !== null && count($override) > 0) {
                    // Explicit override replaces role defaults (see syncAccess).
                    $user->syncRoles(['customer']);
                    $user->syncPermissions(PermissionService::expand($override));
                } else {
                    $user->syncPermissions([]);
                }
            }
        });

        // 3. The JSON override column is superseded by model_has_permissions.
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'permissions_json')) {
                $table->dropColumn('permissions_json');
            }
            // Fortify's guard->login($user, $remember) writes this token.
            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->string('remember_token', 100)->nullable();
            }
        });

        // 4. JWT session settings are obsolete — Fortify owns sessions now.
        DB::table('settings')->whereIn('key', ['jwtSecret', 'jwtExpiresIn'])->delete();
    }

    public function down(): void
    {
        // Best effort: restore the column. Permission/role data in pivot
        // tables stays — the JSON is not recoverable.
        Schema::table('users', function (Blueprint $table) {
            $table->text('permissions_json')->nullable();
        });
    }
};
