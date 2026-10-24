<?php

namespace App\Services\Queue;

use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;

/**
 * Estimates wait time + duration for orders in the smart queue.
 *
 * The estimator runs a small multi-server (multi-operator) queue
 * SIMULATION instead of a plain duration sum:
 *
 *  1. One "slot" per active operator.
 *  2. Slots currently occupied by an IN-PROGRESS order (started, not yet
 *     finished) free up at max(now, that order's estimated end). An
 *     over-running order can only honestly promise "free now" — nobody
 *     knows how much longer it will actually take.
 *  3. Every waiting (not-yet-started) entry, in position order, is then
 *     assigned to the slot that frees up first; the slot's busy-until
 *     grows by that order's duration.
 *
 * The old model summed the durations of waiting orders and divided by the
 * operator count — which silently treated in-progress work as FREE
 * capacity. On a one-operator shop that produced absurd ETAs: a customer
 * queueing behind an order that was being worked on RIGHT NOW was told
 * «انتظار تقریبی: اکنون» because started orders were excluded from the
 * sum entirely.
 *
 * The two numbers (wait / duration) are KEPT SEPARATE so the UI can show
 * them as distinct fields (per the spec).
 */
class WaitTimeEstimatorService implements WaitTimeEstimatorServiceInterface
{
    public function estimateWaitMinutes(?string $queueType = null): int
    {
        // What a NEW order would experience: it joins behind every
        // currently-waiting entry, so simulate all of them and report the
        // earliest slot that would be free afterwards.
        $busyUntil = $this->busyOperatorSlots();
        $waiting = QueueEntry::whereNull('started_at')->orderBy('position')->get();

        foreach ($waiting as $entry) {
            $this->assignToFreeSlot($busyUntil, $entry);
        }

        return $this->minutesUntil(min($busyUntil));
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

        // Every not-yet-started entry physically ahead of this order takes
        // a slot before ours. Our own entry and the ones behind us never
        // consume capacity here. In-progress orders are already baked into
        // the initial slot occupancy (busyOperatorSlots).
        $busyUntil = $this->busyOperatorSlots();
        $aheadEntries = QueueEntry::whereNull('started_at')
            ->where('position', '<', $entry->position)
            ->orderBy('position')
            ->get();

        foreach ($aheadEntries as $ahead) {
            $this->assignToFreeSlot($busyUntil, $ahead);
        }

        // Our turn: the earliest free slot across all operators.
        $startTs = min($busyUntil);
        $duration = (int) $order->estimated_duration_minutes > 0
            ? (int) $order->estimated_duration_minutes
            : $this->estimateDurationMinutes($order->queue_type ?: 'normal');

        return [
            'wait_minutes' => $this->minutesUntil($startTs),
            'duration_minutes' => $duration,
            'start_at' => $this->tsToIso($startTs),
            'end_at' => $this->tsToIso($startTs + $duration * 60),
        ];
    }

    /* ---------- simulation internals ---------- */

    /**
     * One busy-until timestamp (unix ts) per ACTIVE operator slot.
     *
     * Slots occupied by an in-progress order free up at max(now, that
     * order's estimated end); free slots open at "now". Ready orders
     * (finished_at set) have already released their operator and never
     * count as busy.
     *
     * @return int[]
     */
    private function busyOperatorSlots(): array
    {
        $operatorCount = max(1, (int) DB::table('operator_profiles')->where('is_active', true)->count());
        $nowTs = now()->getTimestamp();

        $busy = [];
        $inProgress = QueueEntry::whereNotNull('started_at')
            ->whereNull('finished_at')
            ->orderBy('position')
            ->limit($operatorCount)
            ->get();

        foreach ($inProgress as $entry) {
            $order = Order::find($entry->order_id);
            $endTs = ($order && $order->estimated_end_at) ? strtotime((string) $order->estimated_end_at) : false;
            // Over-run clamp: an order whose estimate already passed can
            // only promise "free now" — never a negative wait.
            $busy[] = max($nowTs, $endTs !== false ? $endTs : $nowTs);
        }

        while (count($busy) < $operatorCount) {
            $busy[] = $nowTs;
        }

        return $busy;
    }

    /**
     * Assign one waiting entry to the slot that frees up first and push
     * that slot's busy-until forward by the order's duration.
     *
     * @param int[] $busyUntil
     */
    private function assignToFreeSlot(array &$busyUntil, QueueEntry $entry): void
    {
        $order = Order::find($entry->order_id);
        if (! $order) {
            return; // orphaned entry — consumes nothing, same as before
        }

        $duration = (int) $order->estimated_duration_minutes > 0
            ? (int) $order->estimated_duration_minutes
            : $this->estimateDurationMinutes($order->queue_type ?: 'normal');

        $slot = array_search(min($busyUntil), $busyUntil, true);
        $busyUntil[$slot] += $duration * 60;
    }

    /** Minutes from now until $ts, rounded up, never negative. */
    private function minutesUntil(int $ts): int
    {
        return max(0, (int) ceil(($ts - now()->getTimestamp()) / 60));
    }

    private function tsToIso(int $ts): string
    {
        return \Illuminate\Support\Carbon::createFromTimestamp($ts)->toIso8601String();
    }
}
