<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuthService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * php artisan admin:set 09191816172[,09199876543]
 *
 * Idempotent first-admin bootstrap: merges phones into the adminPhones
 * setting (DB-backed, not env) and creates/promotes the matching users —
 * the set-admin.ts script port.
 */
class AdminSet extends Command
{
    protected $signature = 'admin:set {phones : شماره‌های مدیر، جدا شده با کاما}';

    protected $description = 'تعیین/افزودن مدیر سامانه در تنظیمات دیتابیس';

    public function handle(): int
    {
        $raw = (string) $this->argument('phones');
        $phones = array_values(array_filter(array_map(
            fn ($p) => AuthService::normalizePhone(trim($p)),
            explode(',', $raw)
        )));

        $invalid = array_filter($phones, fn ($p) => ! AuthService::isValidIranianMobile($p));
        if (count($invalid)) {
            $this->error('شماره‌های نامعتبر: '.implode(', ', $invalid));

            return self::FAILURE;
        }
        if (! count($phones)) {
            $this->error('حداقل یک شماره معتبر لازم است.');

            return self::FAILURE;
        }

        SettingsService::seedDefaults();

        $existing = SettingsService::adminPhones();
        $merged = array_values(array_unique(array_merge($existing, $phones)));
        SettingsService::set('adminPhones', implode(',', $merged));

        foreach ($merged as $phone) {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                if ($user->role !== 'admin') {
                    $user->syncAccess('admin', null);
                    $this->info("{$phone} → ارتقا به مدیر سیستم");
                } else {
                    $this->line("{$phone} → قبلاً مدیر است");
                }
            } else {
                $user = User::create([
                    'phone' => $phone,
                    'name' => 'کاربر '.substr($phone, -4),
                    'role' => 'admin',
                    'wallet_balance' => 0,
                    'is_blocked' => false,
                    'created_at' => now()->toIso8601String(),
                ]);
                $user->syncAccess('admin', null);
                $this->info("{$phone} → مدیر جدید ساخته شد");
            }
        }

        return self::SUCCESS;
    }
}
