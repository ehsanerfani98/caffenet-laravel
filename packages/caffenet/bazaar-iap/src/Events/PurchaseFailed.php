<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Events;

use Caffenet\BazaarIap\Models\BazaarIapTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a purchase could not be completed (cancelled at Bazaar, missing
 * token, or a consume error). Host applications may use it to roll an order
 * back to `failed`.
 */
class PurchaseFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ?BazaarIapTransaction $transaction,
        public readonly string $reason,
    ) {}
}
