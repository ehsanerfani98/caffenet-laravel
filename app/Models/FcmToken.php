<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM Token — a Firebase Cloud Messaging device token registered by a
 * user's browser/app. One user can have many tokens (one per device).
 *
 * The server queries this table by user_id when sending push notifications;
 * inactive tokens (returned as invalid by FCM) are soft-disabled rather
 * than deleted so the audit trail is kept.
 */
class FcmToken extends CaffenetModel
{
    protected const ID_PREFIX = 'fcmt';

    protected $table = 'fcm_tokens';

    protected $fillable = [
        'id', 'user_id', 'token', 'user_agent', 'platform',
        'is_active', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
