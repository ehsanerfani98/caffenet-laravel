<?php

namespace App\Models;

use App\Services\PermissionService;

class User extends CaffenetModel
{
    protected const ID_PREFIX = 'usr';

    protected $table = 'users';

    protected $fillable = [
        'id', 'phone', 'name', 'role', 'wallet_balance', 'is_blocked', 'permissions_json', 'created_at',
    ];

    protected $casts = [
        'wallet_balance' => 'integer',
        'is_blocked' => 'boolean',
    ];

    public function permissionsAttribute(): ?array
    {
        return PermissionService::normalize($this->permissions_json);
    }

    /** Effective permissions (role defaults + per-user override). */
    public function effectivePermissions(): array
    {
        return PermissionService::resolve($this->role, $this->permissions_json);
    }

    public function isAdminPhone(array $adminPhones): bool
    {
        return in_array($this->phone, $adminPhones, true);
    }
}
