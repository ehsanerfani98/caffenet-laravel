<?php

namespace App\Contracts;

/**
 * Software-level advisory lock on a named resource.
 *
 * Compensates for SQLite's coarse locking and the absence of Redis on
 * shared hosting. Acquire returns true when this caller now owns the
 * lock; release drops it only if the owner token matches.
 */
interface QueueLockServiceInterface
{
    /**
     * Block-style acquire: spin up to N retries with sleep between attempts.
     * Auto-expires stale locks (whose expires_at has passed) on each attempt.
     */
    public function acquire(string $resourceKey, int $ttlSeconds = 10, int $maxRetries = 5, int $sleepMs = 100): bool;

    /** Release the lock only if owned by $ownerToken. Returns false if not the owner. */
    public function release(string $resourceKey, string $ownerToken): bool;

    /**
     * Run $callback while holding the lock. Releases on success/failure.
     * Returns the callback's result or throws on failure.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLock(string $resourceKey, callable $callback, int $ttlSeconds = 10);

    /** Purge expired locks. Returns count removed. */
    public function cleanupExpired(): int;
}
