<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Support;

use Caffenet\BazaarIap\Contracts\BazaarIapSettings;
use Caffenet\BazaarIap\Dto\ConsumeResult;
use Caffenet\BazaarIap\Dto\PurchaseResult;
use Caffenet\BazaarIap\Exceptions\BazaarIapException;
use Caffenet\BazaarIap\Exceptions\ConsumeFailedException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Thin, server-side wrapper around the Cafe Bazaar developer REST API.
 *
 * The API secret only ever lives here — it is attached to the outgoing
 * request headers and never returned to a caller, so nothing leaks to the
 * browser (the reference PWA exposed it client-side, which this package fixes).
 *
 *   consume:  POST {consume}/{packageName}/purchases/       body: {token}
 *   validate: GET  {validate}/{packageName}/inapp/{sku}/purchases/{token}/
 */
class BazaarPurchaseClient
{
    public const SECRET_HEADER = 'CAFEBAZAAR-PISHKHAN-API-SECRET';

    public function __construct(
        private readonly BazaarIapSettings $settings,
        private readonly HttpFactory $http,
    ) {}

    /**
     * Finalise ("consume") a purchase so it can be bought again.
     */
    public function consume(string $purchaseToken): ConsumeResult
    {
        $this->assertConfigured();

        $url = $this->settings->consumeUrl().'/'.$this->settings->packageName().'/purchases/';

        try {
            $response = $this->http
                ->timeout($this->settings->timeout())
                ->acceptJson()
                ->withHeaders([self::SECRET_HEADER => $this->settings->apiSecret()])
                ->post($url, ['token' => $purchaseToken]);
        } catch (Throwable $e) {
            throw new BazaarIapException('ارتباط با سرور کافه‌بازار برقرار نشد.', 0, $e);
        }

        $body = $this->json($response);

        if ($response->successful()) {
            return ConsumeResult::success($response->status(), $body);
        }

        return ConsumeResult::failure(
            $response->status(),
            $this->message($body) ?? 'مصرف خرید در کافه‌بازار ناموفق بود.',
            $body
        );
    }

    /**
     * Consume a token, treating "already consumed" as success (idempotent).
     */
    public function consumeOrFail(string $purchaseToken): ConsumeResult
    {
        $result = $this->consume($purchaseToken);

        if (! $result->ok && ! $result->isAlreadyConsumed()) {
            throw new ConsumeFailedException($result);
        }

        return $result;
    }

    /**
     * Introspect a purchase without consuming it.
     */
    public function validate(string $productId, string $purchaseToken): PurchaseResult
    {
        $this->assertConfigured();

        $url = $this->settings->validateUrl().'/'.$this->settings->packageName()
            .'/inapp/'.$productId.'/purchases/'.$purchaseToken.'/';

        try {
            $response = $this->http
                ->timeout($this->settings->timeout())
                ->acceptJson()
                ->withHeaders([self::SECRET_HEADER => $this->settings->apiSecret()])
                ->get($url);
        } catch (Throwable $e) {
            throw new BazaarIapException('ارتباط با سرور کافه‌بازار برقرار نشد.', 0, $e);
        }

        $body = $this->json($response);

        if ($response->successful()) {
            return PurchaseResult::fromBody($response->status(), $body);
        }

        return PurchaseResult::failure(
            $response->status(),
            $this->message($body) ?? 'اعتبارسنجی خرید ناموفق بود.',
            $body
        );
    }

    private function assertConfigured(): void
    {
        if ($this->settings->packageName() === '' || $this->settings->apiSecret() === '') {
            throw new BazaarIapException('پیکربندی کافه‌بازار ناقص است (packageName / apiSecret).');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function message(array $body): ?string
    {
        foreach (['message', 'error', 'detail', 'error_description'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        return null;
    }
}
