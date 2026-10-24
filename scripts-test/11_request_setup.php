<?php

/**
 * Request-mode test setup — the «غیرفعال کردن همه پرداخت‌ها» scenario.
 * paymentsDisabled=1: orders are born `submitted` with paymentMethod='request'
 * and enter the queue immediately (no wallet / gateway).
 * Run: php artisan tinker --execute="require 'scripts-test/11_request_setup.php';"
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== REQUEST-MODE SETUP (paymentsDisabled=1) ===\n";

// ── 1. Settings: SMS in TEST mode, payments fully disabled.
\App\Services\SettingsService::set('smsEnabled', '1');
\App\Services\SettingsService::set('smsTestMode', '1');
\App\Services\SettingsService::set('smsNotifyEnabled', '1');
\App\Services\SettingsService::set('paymentsDisabled', '1');
// Pin the request duration window so the ETA math is deterministic.
\App\Services\SettingsService::set('requestOrderMinDuration', '10');
\App\Services\SettingsService::set('requestOrderMaxDuration', '10');
echo "Settings: smsEnabled=1 smsTestMode=1 paymentsDisabled=1 requestDuration=[10,10]\n";

// ── 2. Users: 1 admin, 2 operators (+active profiles), 6 customers.
$mk = function (string $phone, string $name, string $role, int $balance = 0): User {
    $u = User::where('phone', $phone)->first();
    if (! $u) {
        $u = User::create([
            'phone' => $phone, 'name' => $name, 'role' => $role,
            'wallet_balance' => $balance, 'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);
        $u->syncAccess($role, null);
    }
    return $u;
};

$admin     = $mk('09120000000', 'مدیر سیستم', 'admin');
$operator1 = $mk('09130000001', 'اپراتور یک', 'operator');
$operator2 = $mk('09130000002', 'اپراتور دو', 'operator');
// 6 customers — wallets deliberately EMPTY: request mode must not touch them.
foreach ([
    ['09111111111', 'مشتری علی'],
    ['09122222222', 'مشتری سارا'],
    ['09133333333', 'مشتری رضا'],
    ['09144444444', 'مشتری مریم'],
    ['09155555555', 'مشتری حسین'],
    ['09166666666', 'مشتری نگار'],
] as $i => [$phone, $name]) {
    $mk($phone, $name, 'customer', 0);
}

foreach ([$operator1, $operator2] as $i => $op) {
    DB::table('operator_profiles')->updateOrInsert(
        ['user_id' => $op->id],
        ['id' => 'op-profile-'.($i + 1), 'display_name' => $op->name, 'capacity_per_hour' => 4,
         'is_active' => true, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]
    );
}
echo "Users: admin=".User::where('role','admin')->count()
    ." operators=".User::where('role','operator')->count()
    ." customers=".User::where('role','customer')->count()
    ." | active op profiles=".DB::table('operator_profiles')->where('is_active', true)->count()."\n";

\App\Services\SettingsService::set('adminPhones', json_encode(['09120000000']));

// ── 3. Capacity slots for next 24h.
$created = app(\App\Contracts\SlotReservationServiceInterface::class)->generateSlots(24);
echo "Capacity slots created: {$created} (total now: ".DB::table('capacity_slots')->count().")\n";

// ── 4. Clean slate.
DB::table('queue_entries')->delete();
DB::table('orders')->delete();
DB::table('order_logs')->delete();
DB::table('order_status_histories')->delete();
DB::table('notification_logs')->delete();
DB::table('payments')->delete();
// sms_logs has a `timestamp` column (no created_at); a plain wipe is the
// cleanest reset — SQLite would silently no-op a WHERE on a missing column
// (double-quoted identifier falls back to a string literal).
DB::table('sms_logs')->delete();
echo "Queue/orders/notifications/sms cleaned for a fresh run.\n";

// Sanity: the switch must be exposed to clients exactly like /settings does.
$pub = \App\Services\SettingsService::bool('paymentsDisabled');
echo "paymentsDisabled (business flag) = ".var_export($pub, true)."\n";
echo "=== REQUEST-MODE SETUP DONE ===\n";
