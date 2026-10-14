<?php

namespace App\Services\Capacity;

use App\Contracts\QueueLockServiceInterface;
use App\Contracts\SlotReservationServiceInterface;
use App\Models\CapacitySlot;
use App\Models\OperatorProfile;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reserves and releases capacity slots for "long" queue types.
 *
 * Slots are 30-minute windows (configurable per generation pass) starting
 * on the hour and half-hour. Each active operator gets their own set of
 * slots. Reservation runs inside the "capacity:reserve" lock to serialize
 * concurrent reservations on the same slot.
 */
class SlotReservationService implements SlotReservationServiceInterface
{
    public const SLOT_MINUTES = 30;

    public function __construct(
        private readonly QueueLockServiceInterface $locks,
    ) {}

    public function generateSlots(int $hours = 24): int
    {
        $operators = OperatorProfile::where('is_active', true)->get();
        if ($operators->isEmpty()) {
            return 0;
        }

        $created = 0;
        $start = now()->startOfHour();
        $end = $start->copy()->addHours($hours);
        $slotMinutes = self::SLOT_MINUTES;

        foreach ($operators as $op) {
            $cursor = $start->copy();
            while ($cursor < $end) {
                $slotEnd = $cursor->copy()->addMinutes($slotMinutes);
                $exists = CapacitySlot::where('operator_id', $op->id)
                    ->where('start_at', $cursor->toIso8601String())
                    ->exists();
                if (! $exists) {
                    CapacitySlot::create([
                        'operator_id' => $op->id,
                        'start_at' => $cursor->toIso8601String(),
                        'end_at' => $slotEnd->toIso8601String(),
                        'status' => CapacitySlot::STATUS_OPEN,
                        'created_at' => now()->toIso8601String(),
                        'updated_at' => now()->toIso8601String(),
                    ]);
                    $created++;
                }
                $cursor = $slotEnd;
            }
        }

        return $created;
    }

    public function findNextOpenSlot(int $withinHours = 48): ?CapacitySlot
    {
        $now = now()->toIso8601String();
        $cutoff = now()->addHours($withinHours)->toIso8601String();

        return CapacitySlot::where('status', CapacitySlot::STATUS_OPEN)
            ->where('start_at', '>=', $now)
            ->where('start_at', '<=', $cutoff)
            ->orderBy('start_at')
            ->first();
    }

    public function reserve(CapacitySlot $slot, Order $order): bool
    {
        return $this->locks->withLock('capacity:reserve', function () use ($slot, $order) {
            return DB::transaction(function () use ($slot, $order) {
                $fresh = CapacitySlot::where('id', $slot->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== CapacitySlot::STATUS_OPEN) {
                    return false;
                }

                $fresh->status = CapacitySlot::STATUS_RESERVED;
                $fresh->reserved_by_order_id = $order->id;
                $fresh->updated_at = now()->toIso8601String();
                $fresh->save();

                $order->reserved_slot_id = $fresh->id;
                $order->estimated_start_at = $fresh->start_at;
                $order->estimated_end_at = $fresh->end_at;
                $order->updated_at = now()->toIso8601String();
                $order->save();

                return true;
            });
        }, ttlSeconds: 10);
    }

    public function release(CapacitySlot $slot): void
    {
        $this->locks->withLock('capacity:reserve', function () use ($slot) {
            DB::transaction(function () use ($slot) {
                $fresh = CapacitySlot::where('id', $slot->id)->lockForUpdate()->first();
                if (! $fresh) {
                    return;
                }
                $fresh->status = CapacitySlot::STATUS_OPEN;
                $fresh->reserved_by_order_id = null;
                $fresh->updated_at = now()->toIso8601String();
                $fresh->save();

                if ($fresh->reserved_by_order_id) {
                    DB::table('orders')->where('id', $fresh->reserved_by_order_id)->update([
                        'reserved_slot_id' => null,
                        'updated_at' => now()->toIso8601String(),
                    ]);
                }
            });
        }, ttlSeconds: 10);
    }

    public function listOpenSlots(int $withinHours = 48): Collection
    {
        $now = now()->toIso8601String();
        $cutoff = now()->addHours($withinHours)->toIso8601String();

        return CapacitySlot::with('operator')
            ->where('status', CapacitySlot::STATUS_OPEN)
            ->where('start_at', '>=', $now)
            ->where('start_at', '<=', $cutoff)
            ->orderBy('start_at')
            ->get();
    }
}
