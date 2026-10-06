<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:status — port of db-status.ts: shows migration state,
 * settings count and row counts per table.
 */
class DbStatus extends Command
{
    protected $signature = 'db:status';

    protected $description = 'نمایش وضعیت مایگریشن‌ها و تعداد ردیف جداول';

    public function handle(): int
    {
        $tables = ['users', 'categories', 'services', 'orders', 'order_logs', 'ticket_messages',
            'coupons', 'sms_logs', 'otp_codes', 'payments', 'settings', 'slider_images'];

        $this->info('=== وضعیت جداول ===');
        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $this->line(sprintf('%-18s %d ردیف', $table, $count));
            } catch (\Throwable) {
                $this->line(sprintf('%-18s (جدول وجود ندارد)', $table));
            }
        }

        $settings = DB::table('settings')->count();
        $this->newLine();
        $this->info("تعداد کلیدهای تنظیمات: {$settings}");

        return self::SUCCESS;
    }
}
