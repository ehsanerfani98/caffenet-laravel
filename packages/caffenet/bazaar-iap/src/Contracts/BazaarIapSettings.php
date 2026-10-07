<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Contracts;

/**
 * Resolves the credentials and endpoints the gateway needs at runtime.
 *
 * The package ships a config-backed implementation
 * (@see \Caffenet\BazaarIap\Support\ConfigBazaarIapSettings). Applications that
 * store their credentials in a database can bind their own implementation in
 * the service container — the manager only ever talks to this interface.
 */
interface BazaarIapSettings
{
    /** Whether the gateway is active at all. */
    public function enabled(): bool;

    /** Test mode: produce deep links but never call Cafe Bazaar for real. */
    public function testMode(): bool;

    /** The application package name registered in the Cafe Bazaar panel. */
    public function packageName(): string;

    /** The API secret issued by the Cafe Bazaar panel (server-side only). */
    public function apiSecret(): string;

    /** SKU used when a caller does not pass one explicitly. */
    public function defaultSku(): string;

    /** Package name that receives the money (defaults to the app package). */
    public function dealerPackageName(): string;

    /** Permission scope requested during the in-app login deep link. */
    public function permissionScope(): int;

    /** Absolute URL Bazaar returns to; null means "use the package route". */
    public function redirectUrl(): ?string;

    /** Base URL of the consume endpoint. */
    public function consumeUrl(): string;

    /** Base URL of the validate endpoint. */
    public function validateUrl(): string;

    /** HTTP timeout in seconds for Bazaar API calls. */
    public function timeout(): int;
}
