<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Support;

use Caffenet\BazaarIap\Contracts\BazaarIapSettings;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Default settings resolver — everything comes from `config/bazaar-iap.php`.
 */
class ConfigBazaarIapSettings implements BazaarIapSettings
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('bazaar-iap.enabled', false);
    }

    public function testMode(): bool
    {
        return (bool) $this->config->get('bazaar-iap.test_mode', false);
    }

    public function packageName(): string
    {
        return (string) $this->config->get('bazaar-iap.package_name', '');
    }

    public function apiSecret(): string
    {
        return (string) $this->config->get('bazaar-iap.api_secret', '');
    }

    public function defaultSku(): string
    {
        return (string) $this->config->get('bazaar-iap.default_sku', '');
    }

    public function dealerPackageName(): string
    {
        $dealer = (string) $this->config->get('bazaar-iap.dealer_package_name', '');

        return $dealer !== '' ? $dealer : $this->packageName();
    }

    public function permissionScope(): int
    {
        return (int) $this->config->get('bazaar-iap.permission_scope', 1);
    }

    public function redirectUrl(): ?string
    {
        $url = (string) $this->config->get('bazaar-iap.redirect_url', '');

        if ($url !== '') {
            return $url;
        }

        // Fall back to the package's own callback route so the browser never
        // needs to see the API secret (the consume happens server-side).
        $prefix = trim((string) $this->config->get('bazaar-iap.routes.prefix', 'bazaar-iap'), '/');

        return url('/'.$prefix.'/callback');
    }

    public function consumeUrl(): string
    {
        return rtrim((string) $this->config->get('bazaar-iap.base_urls.consume', ''), '/');
    }

    public function validateUrl(): string
    {
        return rtrim((string) $this->config->get('bazaar-iap.base_urls.validate', ''), '/');
    }

    public function timeout(): int
    {
        return (int) $this->config->get('bazaar-iap.timeout', 20);
    }
}
