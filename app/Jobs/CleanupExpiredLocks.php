<?php

namespace App\Jobs;

use App\Contracts\QueueLockServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 5 minutes.
 *
 * Purges expired rows from queue_locks so the table doesn't grow
 * unbounded. Cheap and idempotent.
 */
class CleanupExpiredLocks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 1;

    public function handle(QueueLockServiceInterface $locks): void
    {
        try {
            $count = $locks->cleanupExpired();
            if ($count > 0) {
                Log::info('CleanupExpiredLocks: removed stale locks', ['count' => $count]);
            }
        } catch (\Throwable $e) {
            Log::error('CleanupExpiredLocks failed', ['error' => $e->getMessage()]);
        }
    }
}
