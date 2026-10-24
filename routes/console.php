<?php

use App\Jobs\CheckNoResponseOrders;
use App\Jobs\CleanupExpiredLocks;
use App\Jobs\RefreshCapacitySnapshot;
use App\Jobs\SendStartReminder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// ────────────────────────────────────────────────────────────────────────
// Existing cleanup — purge files older than fileRetentionDays and expire
// abandoned pending payments (>30 minutes → failed). Runs every 6 hours.
// ────────────────────────────────────────────────────────────────────────
Schedule::command('caffenet:cleanup')->everySixHours();

// ────────────────────────────────────────────────────────────────────────
// Smart queue / capacity scheduler
// ────────────────────────────────────────────────────────────────────────
// All jobs are idempotent and run with $tries = 1; the next minute's run
// picks up anything that failed. Designed for cPanel's per-minute cron
// + queue:work --stop-when-empty --max-time=50 (no Supervisor).
// ────────────────────────────────────────────────────────────────────────

// Every minute: sweep no-response orders (15-min rule).
Schedule::job(new CheckNoResponseOrders)->everyMinute();

// Every minute: regenerate capacity slots + bust the snapshot cache.
Schedule::job(new RefreshCapacitySnapshot)->everyMinute();

// Every minute: send "your order is about to start" reminders (5 min before).
Schedule::job(new SendStartReminder(5))->everyMinute();

// Every 5 minutes: purge expired queue_locks.
Schedule::job(new CleanupExpiredLocks)->everyFiveMinutes();

// Daily at 03:00: backup the SQLite database (shared-hosting friendly).
// VACUUM INTO produces a consistent, fully-checkpointed single-file snapshot;
// a raw file copy is NOT WAL-safe (recent commits may still live in the
// -wal file next to the database). Falls back to the raw copy when VACUUM
// INTO is unavailable (SQLite < 3.27).
Schedule::call(function () {
    $dbPath = config('database.connections.sqlite.database');
    $backupDir = storage_path('app/backups');
    if (! is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    $backupPath = $backupDir.'/db-'.now()->format('Y-m-d').'.sqlite';
    if (! $dbPath || ! file_exists($dbPath)) {
        return;
    }
    if (DB::connection()->getDriverName() === 'sqlite') {
        try {
            @unlink($backupPath); // VACUUM INTO refuses an existing target
            $safe = str_replace("'", "''", (string) $backupPath);
            DB::statement("VACUUM INTO '{$safe}'");
            return;
        } catch (\Throwable $e) {
            // Fall through to the raw copy (legacy behavior).
        }
    }
    @copy($dbPath, $backupPath);
})->dailyAt('03:00')->name('sqlite-backup')->withoutOverlapping();

// ────────────────────────────────────────────────────────────────────────
// Diagnostics: print the LIVE SQLite pragmas of the default connection.
// Used from the Artisan Runner (browser, no SSH) to verify the
// concurrency hardening (WAL / busy_timeout) actually went live after a
// deploy: journal_mode must read "wal" and busy_timeout "10000".
// ────────────────────────────────────────────────────────────────────────
Artisan::command('caffenet:db-pragmas', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->info('driver='.DB::connection()->getDriverName().' — pragmas are SQLite-only; nothing to show.');

        return;
    }

    // [TEST-FIX 2026-10-24] `PRAGMA busy_timeout` returns its value under the
    // column name "timeout" (not "busy_timeout"), so the old $row[0]->busy_timeout
    // lookup always printed an empty value and made a live 10s busy_timeout look
    // dead. Normalize per-pragma column names here.
    $pragma = function (string $name): string {
        $column = $name === 'busy_timeout' ? 'timeout' : $name;
        $row = DB::select("PRAGMA {$name}");

        return (string) ($row[0]->{$column} ?? $row[0]->{$name} ?? '');
    };

    $this->info('journal_mode='.$pragma('journal_mode'));
    $this->info('busy_timeout='.$pragma('busy_timeout'));
    $this->info('synchronous='.$pragma('synchronous'));
    $this->info('foreign_keys='.$pragma('foreign_keys'));
});
