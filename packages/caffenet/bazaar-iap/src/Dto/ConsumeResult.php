<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Dto;

/**
 * Outcome of a consume (finalise purchase) call against Cafe Bazaar.
 */
final class ConsumeResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $statusCode,
        public readonly ?string $message = null,
        public readonly array $body = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function success(int $statusCode, array $body = []): self
    {
        return new self(true, $statusCode, null, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function failure(int $statusCode, ?string $message = null, array $body = []): self
    {
        return new self(false, $statusCode, $message, $body);
    }

    public function isAlreadyConsumed(): bool
    {
        // Bazaar answers 200 for an already-consumed token as well; some
        // proxies surface it as a 409/410 with a descriptive message.
        return in_array($this->statusCode, [409, 410], true)
            || ($this->message !== null && str_contains(strtolower($this->message), 'consum'));
    }

    /**
     * @return array{ok: bool, statusCode: int, message: string|null, body: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'statusCode' => $this->statusCode,
            'message' => $this->message,
            'body' => $this->body,
        ];
    }
}
