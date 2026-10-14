<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operator Profile — capacity metadata for an active staff user.
 *
 * The `users.role` column already distinguishes customer/operator/admin;
 * this table holds the queue-specific capacity (orders/hr) and active flag
 * for users whose role is operator or admin.
 */
class OperatorProfile extends CaffenetModel
{
    protected const ID_PREFIX = 'oprofile';

    protected $table = 'operator_profiles';

    protected $fillable = [
        'id', 'user_id', 'display_name', 'capacity_per_hour', 'is_active',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'capacity_per_hour' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(CapacitySlot::class, 'operator_id');
    }
}
