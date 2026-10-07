<?php

namespace App\Services;

use Caffenet\BazaarIap\Contracts\BazaarIapSettings;

/**
 * Bridges the package's settings contract to this application's database-first
 * `SettingsService` (system panel → payment category). Binding this in the
 * container means every Bazaar credential is managed from the admin panel and
 * never read from `.env`.
 */
class BazaarIapSettingsResolver implements BazaarIapSettings
{
    public function enabled(): bool
    {
        return SettingsService::bool('bazaarEnabled');
    }

    public function testMode(): bool
    {
        return SettingsService::bool('bazaarTestMode');
    }

    public function packageName(): string
    {
        return trim((string) SettingsService::raw('bazaarPackageName'));
    }

    public function apiSecret(): string
    {
        return trim((string) SettingsService::raw('bazaarApiSecret'));
    }

    public function defaultSku(): string
    {
        return trim((string) SettingsService::raw('bazaarSku'));
    }

    public function dealerPackageName(): string
    {
        $dealer = trim((string) SettingsService::raw('bazaarDealerPackageName'));

        return $dealer !== '' ? $dealer : $this->packageName();
    }

    public function permissionScope(): int
    {
        return (int) SettingsService::num('bazaarPermissionScope', 1);
    }

    public function redirectUrl(): ?string
    {
        $url = trim((string) SettingsService::raw('bazaarRedirectUrl'));

        return $url !== '' ? $url : null; // null → package default callback route
    }

    public function consumeUrl(): string
    {
        return rtrim((string) SettingsService::raw('bazaarConsumeUrl'), '/');
    }

    public function validateUrl(): string
    {
        return rtrim((string) SettingsService::raw('bazaarValidateUrl'), '/');
    }

    public function timeout(): int
    {
        return max(1, (int) SettingsService::num('bazaarTimeout', 20));
    }
}
