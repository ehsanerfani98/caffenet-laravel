<?php

namespace App\Console\Commands;

use App\Services\CleanupService;
use Illuminate\Console\Command;

/**
 * php artisan caffenet:cleanup
 *
 * Scheduled maintenance: purge files older than fileRetentionDays and expire
 * abandoned pending payments (>30 minutes → failed). Runs immediately after
 * boot in the original; here via the scheduler every six hours.
 */
class CaffenetCleanup extends Command
{
    protected $signature = 'caffenet:cleanup';

    protected $description = 'پاکسازی فایل‌های منقضی و انقضای پرداخت‌های رهاشده';

    public function handle(): int
    {
        $result = CleanupService::run();
        $this->info("فایل‌های حذف‌شده: {$result['purgedFiles']}");
        $this->info("سفارش‌های منقضی: {$result['expiredOrders']}");

        return self::SUCCESS;
    }
}
