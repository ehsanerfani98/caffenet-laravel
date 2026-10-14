<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacity Slot — a reservable time window for a given operator.
 *
 * Slots are generated in advance (e.g. next 24-48 hours) and either:
 *  - open      → freely reservable
 *  - reserved  → locked to a specific order (reserved_by_order_id)
 *  - completed → work finished within this window
 *  - cancelled → operator unavailable
 */
class CapacitySlot extends CaffenetModel
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN, self::STATUS_RESERVED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    protected const ID_PREFIX = 'slot';

    protected $table = 'capacity_slots';

    protected $fillable = [
        'id', 'operator_id', 'start_at', 'end_at', 'status',
        'reserved_by_order_id', 'created_at', 'updated_at',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(OperatorProfile::class, 'operator_id');
    }
}
