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
            $this->syncPermissions($this->resolvePermissions(PermissionService::expand($overrideKeys)));
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
     * Resolves catalog keys to Permission MODELS for this model's guard,
     * creating any that are missing.
     *
     * Passing models straight to syncPermissions() skips spatie's internal
     * findByName() lookup entirely — that lookup is what throws the opaque
     * PermissionDoesNotExist when a deployment has not run the permission
     * migrations yet (and it can also trip on a stale permission cache).
     *
     * Missing keys are logged (with the `php artisan migrate` remedy) and then
     * created on the fly — idempotent and self-healing, and it also repairs the
     * root cause behind the admin's empty "User Transactions" list (the role
     * simply had no wallet.* keys).
     *
     * @return array<int, \Spatie\Permission\Contracts\Permission>
     */
    private function resolvePermissions(array $keys): array
    {
        if (count($keys) === 0) {
            return [];
        }

        $guard = $this->getDefaultGuardName();
        $existing = Permission::query()
            ->whereIn('name', $keys)
            ->where('guard_name', $guard)
            ->get()
            ->keyBy('name');

        $resolved = [];
        $missing = [];
        foreach ($keys as $key) {
            if ($existing->has($key)) {
                $resolved[$key] = $existing->get($key);
            } else {
                $missing[] = $key;
            }
        }

        if (count($missing) > 0) {
            Log::warning('syncAccess: catalog permissions missing from the database — created on the fly; run `php artisan migrate` on the server.', [
                'guard' => $guard,
                'role' => $this->role,
                'user_id' => $this->id,
                'missing' => $missing,
            ]);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            foreach ($missing as $key) {
                $resolved[$key] = Permission::findOrCreate($key, $guard);
            }
        }

        return array_values($resolved);
    }

    public function isAdminPhone(array $adminPhones): bool
    {
        return in_array($this->phone, $adminPhones, true);
    }
}
