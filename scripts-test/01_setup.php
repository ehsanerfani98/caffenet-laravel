<?php

/**
 * Test setup — users, operator profiles, settings, capacity slots.
 * Run: php artisan tinker --execute="require 'scripts-test/01_setup.php';"
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== TEST SETUP ===\n";

// ── 1. Settings: SMS enabled in TEST mode (no real provider needed),
//        order notifications ON, payments normal (wallet flow).
\App\Services\SettingsService::set('smsEnabled', '1');
\App\Services\SettingsService::set('smsTestMode', '1');
\App\Services\SettingsService::set('smsNotifyEnabled', '1');
\App\Services\SettingsService::set('paymentsDisabled', '0');
echo "Settings: smsEnabled=1 smsTestMode=1 smsNotifyEnabled=1 paymentsDisabled=0\n";

// ── 2. Users: 1 admin, 2 operators (+active profiles), 4 customers.
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
$c1 = $mk('09111111111', 'مشتری علی', 'customer', 2_000_000);
$c2 = $mk('09122222222', 'مشتری سارا', 'customer', 1_000_000);
$c3 = $mk('09133333333', 'مشتری رضا', 'customer', 500_000);
$c4 = $mk('09144444444', 'مشتری مریم', 'customer', 300_000);

// Operator profiles (capacity_per_hour default 4 like the UI default).
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

// ── 3. Fresh admin-phone setting so AdminUsersSeeder would also work.
\App\Services\SettingsService::set('adminPhones', json_encode(['09120000000']));

// ── 4. Capacity slots for next 24h.
$created = app(\App\Contracts\SlotReservationServiceInterface::class)->generateSlots(24);
echo "Capacity slots created: {$created} (total now: ".DB::table('capacity_slots')->count().")\n";

// ── 5. Clean slate for queue + orders + logs from previous runs.
DB::table('queue_entries')->delete();
DB::table('orders')->delete();
DB::table('order_logs')->delete();
DB::table('order_status_histories')->delete();
DB::table('notification_logs')->delete();
DB::table('payments')->delete();
echo "Queue/orders/notifications cleaned for a fresh run.\n";

echo "=== SETUP DONE ===\n";
