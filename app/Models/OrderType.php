<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order Type — Quick / Normal / Long.
 *
 * Drives the queue's behavior:
 *  - quick  → inline (no slot reservation), short duration, high priority
 *  - normal → standard queue with ETA
 *  - long   → requires slot reservation (next available window today/tomorrow)
 */
class OrderType extends CaffenetModel
{
    public const KEY_QUICK = 'quick';
    public const KEY_NORMAL = 'normal';
    public const KEY_LONG = 'long';

    public const KEYS = [self::KEY_QUICK, self::KEY_NORMAL, self::KEY_LONG];

    protected const ID_PREFIX = 'otype';

    protected $table = 'order_types';

    protected $fillable = [
        'id', 'key', 'title', 'base_duration_minutes', 'priority_level',
        'allow_inline', 'description',
    ];

    protected $casts = [
        'base_duration_minutes' => 'integer',
        'priority_level' => 'integer',
        'allow_inline' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'queue_type', 'key');
    }
}
