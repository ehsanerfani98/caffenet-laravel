<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap;

use Caffenet\BazaarIap\Contracts\BazaarIapSettings;
use Caffenet\BazaarIap\Dto\ConsumeResult;
use Caffenet\BazaarIap\Dto\PurchaseResult;
use Caffenet\BazaarIap\Support\BazaarDeepLink;
use Caffenet\BazaarIap\Support\BazaarPurchaseClient;

/**
 * Public entry point of the gateway. Resolve it from the container or via the
 * `BazaarIap` facade.
 */
class BazaarIap
{
    public function __construct(
        private readonly BazaarIapSettings $settings,
        private readonly BazaarPurchaseClient $client,
    ) {}

    public function settings(): BazaarIapSettings
    {
        return $this->settings;
    }

    public function client(): BazaarPurchaseClient
    {
        return $this->client;
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled();
    }

    public function isTestMode(): bool
    {
        return $this->settings->testMode();
    }

    /** SKU resolution — the caller's value wins, otherwise the configured default. */
    public function resolveSku(?string $sku = null): string
    {
        $sku = $sku !== null ? trim($sku) : '';

        return $sku !== '' ? $sku : $this->settings->defaultSku();
    }

    /**
     * Append parameters (visit, status, sku, …) to the configured redirect URL.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function redirectUrl(array $params = []): string
    {
        return BazaarDeepLink::redirect($this->callbackUrl(), $params);
    }

    /** The configured callback URL, without any extra parameters. */
    public function callbackUrl(): string
    {
        $url = (string) ($this->settings->redirectUrl() ?? '');

        if ($url === '') {
            // Fall back to the package's own callback route so a host that
            // binds a minimal settings resolver still works out of the box.
            $prefix = trim((string) config('bazaar-iap.routes.prefix', 'bazaar-iap'), '/');
            $url = url('/'.$prefix.'/callback');
        }

        return $url;
    }

    /**
     * Build the in-app login deep link. When `$redirectUrl` already carries
     * `status=login`, the browser callback resumes the payment automatically.
     */
    public function loginDeepLink(string $redirectUrl, ?string $sku = null): string
    {
        return BazaarDeepLink::login(
            $this->settings->packageName(),
            $redirectUrl,
            $this->settings->permissionScope()
        );
    }

    /** Build the in-app payment deep link. */
    public function paymentDeepLink(string $redirectUrl, ?string $sku = null): string
    {
        return BazaarDeepLink::payment(
            $this->resolveSku($sku),
            $this->settings->dealerPackageName(),
            $redirectUrl
        );
    }

    /** Finalise a purchase token at Cafe Bazaar. */
    public function consume(string $purchaseToken): ConsumeResult
    {
        return $this->client->consume($purchaseToken);
    }

    /** Finalise a token, treating "already consumed" as success. */
    public function consumeOrFail(string $purchaseToken): ConsumeResult
    {
        return $this->client->consumeOrFail($purchaseToken);
    }

    /** Introspect a purchase without consuming it. */
    public function validate(string $productId, string $purchaseToken): PurchaseResult
    {
        return $this->client->validate($productId, $purchaseToken);
    }
}
