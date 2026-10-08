<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Exceptions;

use Caffenet\BazaarIap\Dto\ConsumeResult;

/**
 * Thrown when a purchase token could not be consumed at Cafe Bazaar.
 */
class ConsumeFailedException extends BazaarIapException
{
    public function __construct(public readonly ConsumeResult $result)
    {
        parent::__construct(
            $result->message ?? 'مصرف خرید در کافه‌بازار ناموفق بود.'
        );
    }
}
