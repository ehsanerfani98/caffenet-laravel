<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Events;

use Caffenet\BazaarIap\Dto\ConsumeResult;
use Caffenet\BazaarIap\Models\BazaarIapTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after Cafe Bazaar confirms (and the package consumes) a purchase.
 *
 * Host applications listen for this event to fulfil the purchase — e.g. mark
 * an order as paid or credit a wallet — without the package needing to know
 * anything about their domain.
 */
class PurchaseConsumed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly BazaarIapTransaction $transaction,
        public readonly ConsumeResult $result,
        public readonly string $purchaseToken,
    ) {}
}
