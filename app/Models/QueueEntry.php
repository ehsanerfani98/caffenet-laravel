<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Queue Entry — position tracking for a submitted order.
 *
 * Created when an order transitions to `submitted` (post-payment). Tracks
 * the order's position in the queue, when it entered, when work started,
 * and when work finished. The `no_response_deadline` field supports the
 * "if the customer doesn't respond within 15 minutes of their turn, push
 * them to the back of the queue" rule.
 */
class QueueEntry extends CaffenetModel
{
    protected const ID_PREFIX = 'qe';

    protected $table = 'queue_entries';

    protected $fillable = [
        'id', 'order_id', 'position', 'entered_at', 'started_at',
        'finished_at', 'no_response_deadline', 'requeue_count',
    ];

    protected $casts = [
        'position' => 'integer',
        'requeue_count' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
