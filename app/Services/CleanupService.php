<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Scheduled cleanup — a faithful port of server/cleanup.ts.
 *
 * 1. purgeExpiredFiles: deletes uploads older than fileRetentionDays and
 *    clears their references from orders.customer_files_json / result_files_json.
 * 2. expireAbandonedPayments: pending_payment orders older than 30 minutes go
 *    failed (with an order log) and their pending payments are closed.
 */
class CleanupService
{
    public const ABANDON_PAYMENT_MINUTES = 30;

    public static function run(): array
    {
        return [
            'purgedFiles' => self::purgeExpiredFiles(),
            'expiredOrders' => self::expireAbandonedPayments(),
        ];
    }

    public static function purgeExpiredFiles(): int
    {
        $days = SettingsService::fileRetentionDays();
        $cutoff = now()->subDays($days)->getTimestamp();
        $purged = 0;

        foreach (['orders' => 'customer_files_json', 'orders_result' => 'result_files_json'] as $table => $col) {
            $rows = $table === 'orders'
                ? DB::table('orders')->select('id', 'customer_files_json')->get()
                : DB::table('orders')->select('id', 'result_files_json')->get();

            foreach ($rows as $row) {
                $files = json_decode($table === 'orders' ? $row->customer_files_json : $row->result_files_json, true);
                if (! is_array($files) || count($files) === 0) {
                    continue;
                }
                $kept = [];
                $changed = false;
                foreach ($files as $file) {
                    $uploadedAt = strtotime((string) ($file['uploadedAt'] ?? ''));
                    if ($uploadedAt && $uploadedAt < $cutoff) {
                        // Remove from disk and drop the reference.
                        $name = basename(parse_url((string) ($file['url'] ?? ''), PHP_URL_PATH) ?: '');
                        if ($name !== '') {
                            Storage::disk('uploads')->delete($name);
                        }
                        $purged++;
                        $changed = true;
                    } else {
                        $kept[] = $file;
                    }
                }
                if ($changed) {
                    if ($table === 'orders') {
                        DB::table('orders')->where('id', $row->id)->update(['customer_files_json' => json_encode($kept, JSON_UNESCAPED_UNICODE)]);
                    } else {
                        DB::table('orders')->where('id', $row->id)->update(['result_files_json' => json_encode($kept, JSON_UNESCAPED_UNICODE)]);
                    }
                }
            }
        }

        return $purged;
    }

    public static function expireAbandonedPayments(): int
    {
        $cutoff = now()->subMinutes(self::ABANDON_PAYMENT_MINUTES)->toIso8601String();
        $now = now()->toIso8601String();

        $stale = DB::table('orders')
            ->where('status', 'pending_payment')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $order) {
            DB::transaction(function () use ($order, $now) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('status', 'pending_payment')
                    ->update(['status' => 'failed', 'updated_at' => $now]);

                DB::table('order_logs')->insert([
                    'id' => 'log-'.\Illuminate\Support\Str::uuid(),
                    'order_id' => $order->id,
                    'action' => 'پرداخت ناموفق بود',
                    'performed_by' => 'سیستم',
                    'note' => 'پرداخت در مهلت ۳۰ دقیقه‌ای تکمیل نشد.',
                    'timestamp' => $now,
                ]);

                DB::table('payments')
                    ->where('order_id', $order->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);
            });
        }

        return count($stale);
    }
}
