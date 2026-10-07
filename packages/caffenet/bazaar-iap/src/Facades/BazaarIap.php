<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static bool isTestMode()
 * @method static string resolveSku(?string $sku = null)
 * @method static string redirectUrl(array $params = [])
 * @method static string callbackUrl()
 * @method static string loginDeepLink(string $redirectUrl, ?string $sku = null)
 * @method static string paymentDeepLink(string $redirectUrl, ?string $sku = null)
 * @method static \Caffenet\BazaarIap\Dto\ConsumeResult consume(string $purchaseToken)
 * @method static \Caffenet\BazaarIap\Dto\ConsumeResult consumeOrFail(string $purchaseToken)
 * @method static \Caffenet\BazaarIap\Dto\PurchaseResult validate(string $productId, string $purchaseToken)
 * @method static \Caffenet\BazaarIap\Contracts\BazaarIapSettings settings()
 *
 * @see \Caffenet\BazaarIap\BazaarIap
 */
class BazaarIap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Caffenet\BazaarIap\BazaarIap::class;
    }
}
