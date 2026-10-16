<?php

namespace App\Services\Queue;

use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;

/**
 * Estimates wait time + duration for a NEW order.
 *
 * Algorithm:
 *  - wait = Σ estimated_duration_minutes of all orders currently ahead
 *    in the queue (started_at IS NULL), divided by the number of active
 *    operators (parallelism). Minimum 0.
 *  - duration = order_type.base_duration_minutes for the requested type
 *    (defaults to "normal" if missing).
 *
 * The two numbers are KEPT SEPARATE so the UI can show them as distinct
 * fields (per the spec).
 */
class WaitTimeEstimatorService implements WaitTimeEstimatorServiceInterface
{
    public function estimateWaitMinutes(?string $queueType = null): int
    {
        // Sum of estimated durations of waiting orders ahead.
        $query = QueueEntry::whereNull('started_at');
        $ahead = $query->get();

        if ($ahead->isEmpty()) {
            return 0;
        }

        $totalMinutes = 0;
        foreach ($ahead as $entry) {
            $order = Order::find($entry->order_id);
            if (! $order) {
                continue;
            }
            $totalMinutes += $this->estimateDurationMinutes($order->queue_type ?: 'normal');
        }

        // Divide by active operator count (parallelism).
        $operators = (int) DB::table('operator_profiles')->where('is_active', true)->count();
        $operators = max(1, $operators);

        return (int) ceil($totalMinutes / $operators);
    }

    public function estimateDurationMinutes(string $queueType): int
    {
        $type = OrderType::where('key', $queueType)->first();
        return $type?->base_duration_minutes ?? match ($queueType) {
            OrderType::KEY_QUICK => 5,
            OrderType::KEY_NORMAL => 20,
            OrderType::KEY_LONG => 60,
            default => 20,
        };
    }

    public function estimateTotalMinutes(?string $queueType = null): int
    {
        $queueType = $queueType ?: 'normal';
        return $this->estimateWaitMinutes($queueType) + $this->estimateDurationMinutes($queueType);
    }

    public function estimateStartAt(?string $queueType = null): string
    {
        $wait = $this->estimateWaitMinutes($queueType);
        return now()->addMinutes($wait)->toIso8601String();
    }

    public function estimateEndAt(?string $queueType = null): string
    {
        $total = $this->estimateTotalMinutes($queueType);
        return now()->addMinutes($total)->toIso8601String();
    }

    public function estimateForOrder(Order $order): ?array
    {
        $entry = QueueEntry::where('order_id', $order->id)->first();
        if (! $entry) {
            return null;
        }

        // Sum durations of every not-yet-started entry physically ahead of
        // this order. Entries behind us and our own entry never count.
        $aheadEntries = QueueEntry::whereNull('started_at')
            ->where('position', '<', $entry->position)
            ->get();

        $totalMinutes = 0;
        foreach ($aheadEntries as $ahead) {
            $aheadOrder = Order::find($ahead->order_id);
            if (! $aheadOrder) {
                continue;
            }
            $totalMinutes += $aheadOrder->estimated_duration_minutes
                ?: $this->estimateDurationMinutes($aheadOrder->queue_type ?: 'normal');
        }

        $operators = (int) DB::table('operator_profiles')->where('is_active', true)->count();
        $operators = max(1, $operators);

        $wait = (int) ceil($totalMinutes / $operators);
        $duration = $order->estimated_duration_minutes
            ?: $this->estimateDurationMinutes($order->queue_type ?: 'normal');

        return [
            'wait_minutes' => $wait,
            'duration_minutes' => $duration,
            'start_at' => now()->addMinutes($wait)->toIso8601String(),
            'end_at' => now()->addMinutes($wait + $duration)->toIso8601String(),
        ];
    }
}
