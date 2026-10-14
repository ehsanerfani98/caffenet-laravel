<?php

namespace App\Console\Commands;

use App\Contracts\SlotReservationServiceInterface;
use Illuminate\Console\Command;

/**
 * php artisan queue:slots:generate
 *
 * Pre-generates capacity_slots for the next N hours (default 24) for every
 * active operator. Safe to run repeatedly — existing slots are skipped.
 *
 * Useful for the first deploy (before the cron scheduler has run) and for
 * regenerating slots after adding/removing operators.
 */
class QueueSlotsGenerate extends Command
{
    protected $signature = 'queue:slots:generate
                            {--hours=24 : Number of hours ahead to generate slots for}';

    protected $description = 'تولید نوبت‌های ظرفیت برای اپراتورهای فعال (۲۴ ساعت آینده)';

    public function handle(SlotReservationServiceInterface $slots): int
    {
        $hours = (int) $this->option('hours');
        $hours = max(1, min(168, $hours));

        $created = $slots->generateSlots(hours: $hours);
        $this->info("نوبت‌های جدید ایجادشده: {$created} (برای {$hours} ساعت آینده)");

        return self::SUCCESS;
    }
}
