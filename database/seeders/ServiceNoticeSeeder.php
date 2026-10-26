<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports the is_notice_enabled master switch from a kafinet services
 * export (the JSON snapshot produced by the upstream system), matching
 * rows BY TITLE:
 *
 *   { "items": [ { "title": "نوبت دهی مطب دکتر", "isNoticeEnabled": true, … }, … ] }
 *
 * Only is_notice_enabled is written — notice_type / notice_text / benefit
 * stay exactly as configured, so re-running after a fresh export never
 * clobbers hand-edited notice content.
 *
 * File resolution (first hit wins):
 *   1. the $file argument (used by the test),
 *   2. the KAFINET_SERVICES_JSON env var,
 *   3. the newest database/data/kafinet-services-*.json in the repo.
 *
 * Idempotent — safe to run any number of times. Titles are matched after
 * trimming; JSON rows whose title has no service counterpart are counted
 * and reported but never fail the run.
 *
 * Run on shared hosting through the Artisan Runner:
 *   /api/_artisan/run/db:seed:service-notices?token=…
 */
class ServiceNoticeSeeder extends Seeder
{
    public function run(?string $file = null): void
    {
        $path = $file
            ?: (string) (env('KAFINET_SERVICES_JSON') ?: '');

        if ($path === '' || ! is_file($path)) {
            $path = $this->newestExport();
        }

        if (! $path || ! is_file($path)) {
            if ($this->command) {
                $this->command->warn('ServiceNoticeSeeder: فایل JSON خروجی خدمات یافت نشد (database/data/kafinet-services-*.json).');
            }

            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $items = is_array($decoded)
            ? (array) ($decoded['items'] ?? $decoded) // {items:[…]} or a bare […]
            : [];

        $enabled = 0;
        $disabled = 0;
        $unmatched = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $value = (bool) ($item['isNoticeEnabled'] ?? false);
            $affected = DB::table('services')
                ->where(DB::raw('TRIM(title)'), $title)
                ->update(['is_notice_enabled' => $value]);

            if ($affected > 0) {
                $value ? $enabled += $affected : $disabled += $affected;
            } else {
                $unmatched[] = $title;
            }
        }

        if ($this->command) {
            $this->command->info(sprintf(
                'ServiceNoticeSeeder: از فایل %s — %d فعال / %d غیرفعال%s',
                basename($path),
                $enabled,
                $disabled,
                $unmatched ? PHP_EOL.'عنوان‌های بدون معادل: '.implode(' | ', array_slice($unmatched, 0, 20)) : ''
            ));
        }
    }

    /** Newest kafinet services export shipped in the repo (if any). */
    private function newestExport(): ?string
    {
        $files = glob(database_path('data/kafinet-services-*.json')) ?: [];
        if (! $files) {
            return null;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
