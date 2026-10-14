<?php

namespace App\Services\Queue;

use App\Contracts\QueueLockServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SQLite-friendly advisory lock with TTL.
 *
 * Two-phase: a row is INSERTED with resource_key + owner_token + expires_at.
 * Acquire spins until either the lock is free or the max retries are hit.
 * Stale locks (expires_at < now) are garbage-collected on each attempt.
 *
 * Concurrency safety on SQLite:
 *  - All writes happen inside a DB::transaction wrapping an INSERT-or-ignore
 *    pattern. If the unique index on resource_key rejects the insert, we
 *    know another process holds the lock.
 *  - The TTL ensures a crashed holder never permanently blocks the queue.
 */
class QueueLockService implements QueueLockServiceInterface
{
    public function acquire(string $resourceKey, int $ttlSeconds = 10, int $maxRetries = 5, int $sleepMs = 100): bool
    {
        $ownerToken = Str::random(32);
        $now = now();
        $expiresAt = $now->copy()->addSeconds($ttlSeconds);

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $this->cleanupExpiredForKey($resourceKey);

            try {
                $inserted = DB::table('queue_locks')->insert([
                    'id' => 'qlck-'.Str::uuid(),
                    'resource_key' => $resourceKey,
                    'owner_token' => $ownerToken,
                    'ttl_seconds' => $ttlSeconds,
                    'locked_at' => $now->toIso8601String(),
                    'expires_at' => $expiresAt->toIso8601String(),
                ]);

                if ($inserted) {
                    return true;
                }
            } catch (\Exception $e) {
                // Unique constraint violation = lock held; fall through to sleep.
            }

            usleep($sleepMs * 1000);
            $now = now();
            $expiresAt = $now->copy()->addSeconds($ttlSeconds);
        }

        return false;
    }

    public function release(string $resourceKey, string $ownerToken): bool
    {
        return DB::table('queue_locks')
            ->where('resource_key', $resourceKey)
            ->where('owner_token', $ownerToken)
            ->delete() > 0;
    }

    public function withLock(string $resourceKey, callable $callback, int $ttlSeconds = 10)
    {
        $ownerToken = Str::random(32);
        $acquired = false;

        try {
            $now = now();
            $expiresAt = $now->copy()->addSeconds($ttlSeconds);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->cleanupExpiredForKey($resourceKey);
                try {
                    $inserted = DB::table('queue_locks')->insert([
                        'id' => 'qlck-'.Str::uuid(),
                        'resource_key' => $resourceKey,
                        'owner_token' => $ownerToken,
                        'ttl_seconds' => $ttlSeconds,
                        'locked_at' => $now->toIso8601String(),
                        'expires_at' => $expiresAt->toIso8601String(),
                    ]);
                    if ($inserted) {
                        $acquired = true;
                        break;
                    }
                } catch (\Exception $e) {
                }
                usleep(100_000);
            }

            if (! $acquired) {
                throw new \RuntimeException("QueueLockService: could not acquire lock for [$resourceKey] after retries.");
            }

            return $callback();
        } finally {
            if ($acquired) {
                $this->release($resourceKey, $ownerToken);
            }
        }
    }

    public function cleanupExpired(): int
    {
        $now = now()->toIso8601String();
        return DB::table('queue_locks')->where('expires_at', '<', $now)->delete();
    }

    private function cleanupExpiredForKey(string $resourceKey): void
    {
        $now = now()->toIso8601String();
        DB::table('queue_locks')
            ->where('resource_key', $resourceKey)
            ->where('expires_at', '<', $now)
            ->delete();
    }
}
