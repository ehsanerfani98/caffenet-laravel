<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Dto;

/**
 * Outcome of a validate (purchase introspection) call against Cafe Bazaar.
 *
 * Bazaar's validate endpoint returns a JSON document describing the purchase
 * state and consumption state for a given token.
 */
final class PurchaseResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $statusCode,
        public readonly ?string $productId = null,
        public readonly ?string $purchaseToken = null,
        public readonly ?string $purchaseState = null,
        public readonly ?string $consumptionState = null,
        public readonly ?int $purchaseTime = null,
        public readonly ?string $message = null,
        public readonly array $body = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromBody(int $statusCode, array $body): self
    {
        return new self(
            ok: $statusCode >= 200 && $statusCode < 300,
            statusCode: $statusCode,
            productId: isset($body['productId']) ? (string) $body['productId'] : null,
            purchaseToken: isset($body['purchaseToken']) ? (string) $body['purchaseToken'] : null,
            purchaseState: isset($body['purchaseState']) ? (string) $body['purchaseState'] : null,
            consumptionState: isset($body['consumptionState']) ? (string) $body['consumptionState'] : null,
            purchaseTime: isset($body['purchaseTime']) ? (int) $body['purchaseTime'] : null,
            body: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function failure(int $statusCode, ?string $message = null, array $body = []): self
    {
        return new self(false, $statusCode, message: $message, body: $body);
    }

    public function isPurchased(): bool
    {
        return $this->ok && $this->purchaseState === 'purchased';
    }

    public function isConsumed(): bool
    {
        return $this->ok && $this->consumptionState === 'consumed';
    }
}
