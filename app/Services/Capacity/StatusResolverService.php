<?php

namespace App\Services\Capacity;

use App\Contracts\StatusResolverServiceInterface;
use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Contracts\SlotReservationServiceInterface;
use App\Models\Order;

/**
 * Resolves the global capacity tier shown at the top of the site.
 *
 *   🟢 green   — wait ≤ 15 min
 *   🟡 yellow  — wait 15-30 min
 *   🟠 orange  — wait 30-60 min
 *   🔴 red     — wait > 60 min OR no slot today (long-type blocked)
 *
 * Uses WaitTimeEstimatorService for the ETA, and SlotReservationService
 * for "first available slot today" lookup when the queue is heavily
 * backlogged.
 */
class StatusResolverService implements StatusResolverServiceInterface
{
    public function __construct(
        private readonly WaitTimeEstimatorServiceInterface $estimator,
        private readonly SlotReservationServiceInterface $slots,
    ) {}

    public function currentTier(): string
    {
        $eta = $this->estimator->estimateWaitMinutes();

        if ($eta <= 15) {
            return Order::CAPACITY_GREEN;
        }
        if ($eta <= 30) {
            return Order::CAPACITY_YELLOW;
        }
        if ($eta <= 60) {
            return Order::CAPACITY_ORANGE;
        }

        // >60 min → check if any slot is open today; if not, red.
        $nextSlot = $this->slots->findNextOpenSlot(withinHours: 12);
        if (! $nextSlot) {
            return Order::CAPACITY_RED;
        }

        return Order::CAPACITY_ORANGE;
    }

    public function currentTitle(): string
    {
        return Order::CAPACITY_TITLES[$this->currentTier()] ?? 'ظرفیت نامشخص';
    }

    public function currentEtaMinutes(): int
    {
        return $this->estimator->estimateWaitMinutes();
    }

    public function snapshot(): array
    {
        $tier = $this->currentTier();
        $eta = $this->currentEtaMinutes();
        $slot = $this->slots->findNextOpenSlot(withinHours: 48);

        return [
            'tier' => $tier,
            'title' => Order::CAPACITY_TITLES[$tier] ?? 'ظرفیت نامشخص',
            'eta_minutes' => $eta,
            'first_slot_at' => $slot?->start_at,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
