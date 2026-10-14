<?php

namespace App\Jobs;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * For every order in the queue whose estimated_start_at is within the
 * next $minutesBefore minutes, send a "your order is about to start"
 * reminder. Idempotent via a per-order Cache key.
 */
class SendStartReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 50;
    public $tries = 1;

    public function __construct(
        public readonly int $minutesBefore = 5,
    ) {}

    public function handle(NotificationDispatcherServiceInterface $notifications): void
    {
        $cutoff = now()->addMinutes($this->minutesBefore)->toIso8601String();
        $now = now()->toIso8601String();

        $orders = Order::where('status', Order::STATUS_SUBMITTED)
            ->whereNotNull('estimated_start_at')
            ->where('estimated_start_at', '>=', $now)
            ->where('estimated_start_at', '<=', $cutoff)
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $key = "reminder:sent:{$order->id}:{$this->minutesBefore}";
            if (\Illuminate\Support\Facades\Cache::store('database')->has($key)) {
                continue;
            }
            try {
                $notifications->sendStartReminder($order, $this->minutesBefore);
                \Illuminate\Support\Facades\Cache::store('database')->put($key, true, 3600);
            } catch (\Throwable $e) {
                Log::error('SendStartReminder failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
