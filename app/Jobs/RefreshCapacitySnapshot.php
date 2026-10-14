<?php

namespace App\Jobs;

use App\Contracts\SlotReservationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every minute.
 *
 * Regenerates capacity slots for the next 24h (cheap on SQLite; the
 * SlotReservationService is idempotent — existing slots are skipped) and
 * refreshes the cached capacity snapshot used by the public
 * /api/capacity/current endpoint.
 */
class RefreshCapacitySnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 50;
    public $tries = 1;

    public function handle(SlotReservationServiceInterface $slots): void
    {
        try {
            $slots->generateSlots(hours: 24);
        } catch (\Throwable $e) {
            Log::error('RefreshCapacitySnapshot: generateSlots failed', ['error' => $e->getMessage()]);
        }

        // Force-bust the capacity cache so the next /api/capacity/current
        // call rebuilds it with fresh data.
        Cache::store('database')->forget('capacity:current');
    }
}
