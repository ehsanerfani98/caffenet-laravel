<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM Push Log — audit trail of every push notification the server
 * attempts to send. Used by the admin "تاریخچه پوش‌های ارسالی" panel.
 */
class FcmPushLog extends CaffenetModel
{
    protected const ID_PREFIX = 'fcmpush';

    protected $table = 'fcm_push_logs';

    protected $fillable = [
        'id', 'channel', 'target', 'title', 'body', 'data_json',
        'status', 'sent_count', 'failed_count', 'error', 'sent_by', 'created_at',
    ];

    protected $casts = [
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
