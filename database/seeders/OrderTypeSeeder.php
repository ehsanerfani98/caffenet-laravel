<?php

namespace Database\Seeders;

use App\Models\OrderType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the three queue types (quick / normal / long) with sensible
 * Persian titles and default durations + priority levels.
 *
 * Idempotent: existing rows are updated in place by key.
 */
class OrderTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'key' => OrderType::KEY_QUICK,
                'title' => 'فوری',
                'base_duration_minutes' => 5,
                'priority_level' => 9,
                'allow_inline' => true,
                'description' => 'کارهای سریع مثل پرینت، ثبت درخواست ساده، دانلود/آپلود فایل که حدود ۵ دقیقه زمان می‌برند.',
            ],
            [
                'key' => OrderType::KEY_NORMAL,
                'title' => 'عادی',
                'base_duration_minutes' => 20,
                'priority_level' => 5,
                'allow_inline' => true,
                'description' => 'ثبت‌نام سامانه‌های دولتی، تکمیل فرم‌های چندمرحله‌ای. زمان تقریبی شروع ۳۰ تا ۴۵ دقیقه.',
            ],
            [
                'key' => OrderType::KEY_LONG,
                'title' => 'زمان‌بر',
                'base_duration_minutes' => 60,
                'priority_level' => 3,
                'allow_inline' => false,
                'description' => 'کارهایی که احتمالاً ۱ ساعت یا بیشتر طول می‌کشند. نیاز به رزرو نوبت دارند.',
            ],
        ];

        foreach ($rows as $row) {
            OrderType::updateOrCreate(
                ['key' => $row['key']],
                array_merge(['id' => 'otype-'.Str::uuid()], $row),
            );
        }
    }
}
