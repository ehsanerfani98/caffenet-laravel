<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Admin users seeder (repeatable/idempotent): converts the adminPhones
 * setting into real admin-role users — 002_admin_users_seeder port.
 * Roles/permissions are applied through spatie (User::syncAccess).
 */
class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        $phones = \App\Services\SettingsService::adminPhones();
        foreach ($phones as $phone) {
            $existing = \App\Models\User::where('phone', $phone)->first();
            if ($existing) {
                if ($existing->role !== 'admin') {
                    $existing->syncAccess('admin', null);
                }
            } else {
                $user = \App\Models\User::create([
                    'phone' => $phone,
                    'name' => 'کاربر '.substr($phone, -4),
                    'role' => 'admin',
                    'wallet_balance' => 0,
                    'is_blocked' => false,
                    'created_at' => now()->toIso8601String(),
                ]);
                $user->syncAccess('admin', null);
            }
        }
    }
}
