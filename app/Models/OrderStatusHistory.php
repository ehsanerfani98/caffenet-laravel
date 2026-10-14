<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Order Status History — append-only audit trail of status transitions.
 *
 * Every status change on an order writes a row here, capturing who changed
 * it (user id or 'system'), when, and an optional note. This powers the
 * timeline view on the order tracking page.
 */
class OrderStatusHistory extends CaffenetModel
{
    protected const ID_PREFIX = 'osh';

    protected $table = 'order_status_histories';

    protected $fillable = [
        'id', 'order_id', 'from_status', 'to_status',
        'changed_by', 'note', 'changed_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
