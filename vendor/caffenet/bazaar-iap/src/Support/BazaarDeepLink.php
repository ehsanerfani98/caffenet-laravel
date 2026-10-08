<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Support;

/**
 * Builds the `bazaar://` deep links and the callback `redirectUrl` that drive
 * the in-app login and in-app payment flows.
 *
 * Mirrors the client-side logic of the reference PWA:
 *   - login   → bazaar://inapplogin?redirectUrl=…&packageName=…&permissionScope=1
 *   - payment → bazaar://in_app?redirectUrl=…&sku=…&dealerPackageName=…
 *
 * After the user finishes, Cafe Bazaar re-opens `redirectUrl` and appends
 * `response=ok` and, for a successful purchase, `purchaseToken=…`.
 */
final class BazaarDeepLink
{
    public const SCHEME_LOGIN = 'bazaar://inapplogin';

    public const SCHEME_PAYMENT = 'bazaar://in_app';

    /** Status marker telling the callback which flow is resuming. */
    public const STATUS_LOGIN = 'login';

    public const STATUS_PAYMENT = 'payment';

    /** Build the in-app login deep link. */
    public static function login(string $packageName, string $redirectUrl, int $permissionScope = 1): string
    {
        return self::SCHEME_LOGIN.'?'.self::query([
            'redirectUrl' => $redirectUrl,
            'packageName' => $packageName,
            'permissionScope' => $permissionScope,
        ]);
    }

    /** Build the in-app payment deep link. */
    public static function payment(string $sku, string $dealerPackageName, string $redirectUrl): string
    {
        return self::SCHEME_PAYMENT.'?'.self::query([
            'redirectUrl' => $redirectUrl,
            'sku' => $sku,
            'dealerPackageName' => $dealerPackageName,
        ]);
    }

    /**
     * Append query parameters to a base redirect URL, preserving any existing
     * query string (Bazaar appends its own `response` / `purchaseToken`).
     *
     * @param  array<string, scalar|null>  $params
     */
    public static function redirect(string $baseUrl, array $params = []): string
    {
        $params = array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        );

        if ($params === []) {
            return $baseUrl;
        }

        $glue = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$glue.self::query($params);
    }

    /**
     * RFC 3986 query string (percent-encodes the nested redirect URL so the
     * whole deep link stays a single, well-formed URI).
     *
     * @param  array<string, scalar|null>  $params
     */
    private static function query(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
