<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification Log — audit trail of every queue-related notification.
 *
 * Because the spec rules out third-party SMS/Telegram/WhatsApp, this table
 * IS the notification layer: the frontend polls orders/{id}/updates and
 * reads its notifications from here. Status field supports retries.
 */
class NotificationLog extends CaffenetModel
{
    public const TYPE_ORDER_STARTED = 'order_started';
    public const TYPE_ORDER_READY = 'order_ready';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_REQUEUED = 'requeued';
    public const TYPE_CANCELLED = 'cancelled';

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_BROWSER = 'browser';

    protected const ID_PREFIX = 'nl';

    protected $table = 'notification_logs';

    protected $fillable = [
        'id', 'order_id', 'user_id', 'type', 'channel',
        'payload', 'status', 'error', 'sent_at', 'created_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
