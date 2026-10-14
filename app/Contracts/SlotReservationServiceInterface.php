<?php

namespace App\Contracts;

use App\Models\CapacitySlot;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Reserves and releases capacity slots for "long" queue types.
 *
 * Slots are generated per active operator for the next N hours. Reservation
 * marks a slot as `reserved` and binds it to an order; release frees it
 * back to `open` (e.g. on order cancellation).
 */
interface SlotReservationServiceInterface
{
    /** Generate capacity slots for the next $hours hours, per active operator. */
    public function generateSlots(int $hours = 24): int;

    /**
     * Find the earliest open slot across all active operators.
     * Returns null if no slot is available within the next $hours hours.
     */
    public function findNextOpenSlot(int $withinHours = 48): ?CapacitySlot;

    /** Reserve a slot for an order. Returns true on success. */
    public function reserve(CapacitySlot $slot, Order $order): bool;

    /** Release a previously reserved slot back to open. */
    public function release(CapacitySlot $slot): void;

    /** List all open slots in the next $hours hours, across operators. */
    public function listOpenSlots(int $withinHours = 48): Collection;
}
