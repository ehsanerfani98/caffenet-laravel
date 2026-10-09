<?php

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends CaffenetModel implements AuthenticatableContract, AuthorizableContract
{
    use AuthenticatableTrait, Authorizable, HasRoles;

    protected const ID_PREFIX = 'usr';

    protected $table = 'users';

    protected $fillable = [
        'id', 'phone', 'name', 'role', 'wallet_balance', 'is_blocked', 'created_at',
    ];

    protected $hidden = [
        'roles', 'permissions',
    ];

    protected $casts = [
        'wallet_balance' => 'integer',
        'is_blocked' => 'boolean',
    ];

    /**
     * Effective permissions (role defaults + per-user override).
     *
     * Spatie is the single source of truth: the union of the role's synced
     * permissions and the user's direct permissions — the umbrella/manage
     * and implicit-view expansion is applied whenever permissions are
     * assigned (see syncAccess), so this list is always concrete.
     */
    public function effectivePermissions(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * Single entry point for applying a role + optional per-user permission
     * override while keeping the legacy `role` column in sync.
     *
     * Semantics preserved from the original permission model:
     *  - admin  → the full catalog via the admin role (never lockable);
     *  - override (non-empty) → replaces the role's defaults entirely; the
     *    generic customer role (zero permissions) carries the spatie role
     *    slot while the display/business `role` column keeps the real role;
     *  - otherwise → the role's own synced default permission set.
     *
     * @param  array|null  $overrideKeys  raw permission keys (null = role defaults)
     */
    public function syncAccess(string $role, ?array $overrideKeys): void
    {
        $this->role = $role;

        if ($role === 'admin') {
            $this->syncRoles([$this->ensureRoleExists('admin')]);
            $this->syncPermissions([]);
        } elseif ($overrideKeys !== null && count($overrideKeys) > 0) {
            $this->syncRoles([$this->ensureRoleExists('customer')]);
            $keys = PermissionService::expand($overrideKeys);
            $this->ensurePermissionsExist($keys);
            $this->syncPermissions($keys);
        } else {
            $this->syncRoles([$this->ensureRoleExists($role)]);
            $this->syncPermissions([]);
        }

        $this->save();
    }

    /**
     * Guarantees a role exists for this model's guard before it is synced, so
     * an unmigrated deployment can never throw RoleDoesNotExist. Idempotent.
     */
    private function ensureRoleExists(string $name): string
    {
        $guard = $this->getDefaultGuardName();
        if (! Role::query()->where('name', $name)->where('guard_name', $guard)->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Role::findOrCreate($name, $guard);
        }

        return $name;
    }

    /**
     * Persists any catalog permission missing for this model's guard so
     * syncPermissions() can never throw the opaque PermissionDoesNotExist on a
     * deployment whose permission migrations have not run yet.
     *
     * Missing keys are logged (with the `php artisan migrate` remedy) and then
     * created on the fly — idempotent and self-healing, and it also repairs the
     * root cause behind the admin's empty "User Transactions" list (the role
     * simply had no wallet.* keys).
     */
    private function ensurePermissionsExist(array $keys): void
    {
        if (count($keys) === 0) {
            return;
        }

        $guard = $this->getDefaultGuardName();
        $existing = Permission::query()
            ->whereIn('name', $keys)
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();
        $missing = array_values(array_diff($keys, $existing));

        if (count($missing) === 0) {
            return;
        }

        Log::warning('syncAccess: catalog permissions missing from the database — created on the fly; run `php artisan migrate` on the server.', [
            'guard' => $guard,
            'role' => $this->role,
            'user_id' => $this->id,
            'missing' => $missing,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($missing as $key) {
            Permission::findOrCreate($key, $guard);
        }
    }

    public function isAdminPhone(array $adminPhones): bool
    {
        return in_array($this->phone, $adminPhones, true);
    }
}
