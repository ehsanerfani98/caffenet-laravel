<?php

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Spatie\Permission\Traits\HasRoles;

class User extends CaffenetModel implements AuthenticatableContract
{
    use AuthenticatableTrait, HasRoles;

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
            $this->syncRoles(['admin']);
            $this->syncPermissions([]);
        } elseif ($overrideKeys !== null && count($overrideKeys) > 0) {
            $this->syncRoles(['customer']);
            $this->syncPermissions(PermissionService::expand($overrideKeys));
        } else {
            $this->syncRoles([$role]);
            $this->syncPermissions([]);
        }

        $this->save();
    }

    public function isAdminPhone(array $adminPhones): bool
    {
        return in_array($this->phone, $adminPhones, true);
    }
}
