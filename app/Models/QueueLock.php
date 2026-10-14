<?php

namespace App\Models;

/**
 * Queue Lock — software-level advisory lock on a named resource.
 *
 * Compensates for SQLite's coarse locking and the lack of Redis on shared
 * hosting. A holder acquires a lock with a TTL (default 10s); if the holder
 * crashes or forgets to release, the lock auto-expires. The `owner_token`
 * prevents a different process from releasing a lock it doesn't own.
 *
 * Typical resource keys:
 *  - "queue:position"   → serializes new queue entries
 *  - "capacity:reserve" → serializes slot reservation
 *  - "order:{id}"       → serializes status transitions on a single order
 */
class QueueLock extends CaffenetModel
{
    protected const ID_PREFIX = 'qlck';

    protected $table = 'queue_locks';

    protected $fillable = [
        'id', 'resource_key', 'owner_token', 'ttl_seconds',
        'locked_at', 'expires_at',
    ];

    protected $casts = [
        'ttl_seconds' => 'integer',
    ];
}
