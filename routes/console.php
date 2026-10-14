<?php

use App\Jobs\CheckNoResponseOrders;
use App\Jobs\CleanupExpiredLocks;
use App\Jobs\RefreshCapacitySnapshot;
use App\Jobs\SendStartReminder;
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
Schedule::call(function () {
    $dbPath = config('database.connections.sqlite.database');
    $backupDir = storage_path('app/backups');
    if (! is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    $backupPath = $backupDir.'/db-'.now()->format('Y-m-d').'.sqlite';
    if (file_exists($dbPath)) {
        @copy($dbPath, $backupPath);
    }
})->dailyAt('03:00')->name('sqlite-backup')->withoutOverlapping();
