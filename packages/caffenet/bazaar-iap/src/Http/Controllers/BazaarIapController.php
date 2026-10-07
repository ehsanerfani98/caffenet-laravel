<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Http\Controllers;

use Caffenet\BazaarIap\BazaarIap;
use Caffenet\BazaarIap\Events\PurchaseConsumed;
use Caffenet\BazaarIap\Events\PurchaseFailed;
use Caffenet\BazaarIap\Models\BazaarIapTransaction;
use Caffenet\BazaarIap\Support\BazaarDeepLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standalone HTTP surface of the gateway.
 *
 * The host application may use these routes as-is, or override the callback
 * with its own (as this application does) and keep using the `BazaarIap`
 * service. The callback performs the consume call server-side and then
 * dispatches `PurchaseConsumed` / `PurchaseFailed` so the host can fulfil the
 * purchase without the package knowing the domain.
 */
class BazaarIapController extends Controller
{
    public function __construct(private readonly BazaarIap $bazaar) {}

    /**
     * Create a visit and return the deep link that starts the flow.
     * `login=true` routes through the in-app login first (auto-resumed).
     */
    public function start(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:191'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:191'],
            'return' => ['nullable', 'string', 'max:2048'],
            'login' => ['nullable', 'boolean'],
        ]);

        $sku = $this->bazaar->resolveSku($data['sku'] ?? null);
        $returnUrl = $this->safeReturn($data['return'] ?? '/');

        $transaction = BazaarIapTransaction::create([
            'package_name' => $this->bazaar->settings()->packageName(),
            'sku' => $sku,
            'purpose' => $data['purpose'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => (int) ($data['amount'] ?? 0),
            'status' => BazaarIapTransaction::STATUS_PENDING,
            'meta' => ['return' => $returnUrl],
        ]);

        if (! empty($data['login'])) {
            $loginRedirect = $this->redirect($transaction, BazaarDeepLink::STATUS_LOGIN, $sku, $returnUrl);
            $deepLink = $this->bazaar->loginDeepLink($loginRedirect, $sku);
            $redirectUrl = $loginRedirect;
        } else {
            $redirectUrl = $this->redirect($transaction, BazaarDeepLink::STATUS_PAYMENT, $sku, $returnUrl);
            $deepLink = $this->bazaar->paymentDeepLink($redirectUrl, $sku);
        }

        return response()->json([
            'gateway' => 'bazaar',
            'visitId' => $transaction->visit_id,
            'sku' => $sku,
            'deepLink' => $deepLink,
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /** Return the in-app login deep link for the caller to open. */
    public function login(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $sku = $this->bazaar->resolveSku((string) $request->query('sku', ''));
        $returnUrl = $this->safeReturn((string) $request->query('return', '/'));

        $redirectUrl = $this->bazaar->redirectUrl([
            'status' => BazaarDeepLink::STATUS_LOGIN,
            'sku' => $sku,
            'return' => $returnUrl,
        ]);

        return response()->json([
            'gateway' => 'bazaar',
            'deepLink' => $this->bazaar->loginDeepLink($redirectUrl, $sku),
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * Bazaar redirect target. Handles the login resume, the purchase result
     * (consume), and the cancel/failure path. Safe to call repeatedly.
     */
    public function callback(Request $request): Response
    {
        $visit = (string) $request->query('visit', '');
        $status = (string) $request->query('status', '');
        $sku = (string) $request->query('sku', '');
        $token = (string) $request->query('purchaseToken', '');
        $response = strtolower((string) $request->query('response', ''));
        $returnUrl = $this->safeReturn((string) $request->query('return', '/'));

        $transaction = $visit !== '' ? BazaarIapTransaction::findByVisit($visit) : null;

        // --- Login finished → resume the payment automatically. ---
        if ($status === BazaarDeepLink::STATUS_LOGIN && $token === '') {
            $sku = $sku !== '' ? $sku : (string) ($transaction->sku ?? $this->bazaar->resolveSku());

            $redirectUrl = $transaction
                ? $this->redirect($transaction, BazaarDeepLink::STATUS_PAYMENT, $sku, $returnUrl)
                : $this->bazaar->redirectUrl([
                    'visit' => $visit,
                    'status' => BazaarDeepLink::STATUS_PAYMENT,
                    'sku' => $sku,
                    'return' => $returnUrl,
                ]);

            if ($transaction) {
                $transaction->forceFill(['status' => BazaarIapTransaction::STATUS_LOGIN])->save();
            }

            return response()->view('bazaar-iap::resume', [
                'deepLink' => $this->bazaar->paymentDeepLink($redirectUrl, $sku),
                'returnUrl' => $returnUrl,
            ]);
        }

        // --- Cancel / explicit failure / missing token. ---
        if ($token === '' || ($response !== '' && $response !== 'ok')) {
            $transaction?->markFailed('بازگشت ناموفق یا لغو‌شده از کافه‌بازار.');
            PurchaseFailed::dispatch($transaction, 'cancelled_or_missing_token');

            return redirect()->to($this->appendReturn($returnUrl, [
                'payment' => 'failed',
                'visit' => $visit,
            ]));
        }

        // --- Idempotent: already consumed on a previous callback. ---
        if ($transaction && $transaction->status === BazaarIapTransaction::STATUS_CONSUMED) {
            return redirect()->to($this->appendReturn($returnUrl, [
                'payment' => 'success',
                'visit' => $visit,
            ]));
        }

        $result = $this->bazaar->consume($token);

        if (! $result->ok && ! $result->isAlreadyConsumed()) {
            if ($transaction) {
                $transaction->forceFill(['purchase_token' => $token])->save();
                $transaction->markFailed($result->message);
            }
            PurchaseFailed::dispatch($transaction, 'consume_failed');

            return redirect()->to($this->appendReturn($returnUrl, [
                'payment' => 'failed',
                'visit' => $visit,
            ]));
        }

        if ($transaction) {
            $transaction->forceFill(['purchase_token' => $token])->save();
            $transaction->markConsumed($result->message);

            // The host fulfils the purchase by listening to this event.
            PurchaseConsumed::dispatch($transaction, $result, $token);
        }

        return redirect()->to($this->appendReturn($returnUrl, [
            'payment' => 'success',
            'visit' => $visit,
        ]));
    }

    /** Pollable transaction status (used by SPAs after returning). */
    public function status(Request $request): JsonResponse
    {
        $visit = (string) $request->query('visit', '');

        $transaction = $visit !== '' ? BazaarIapTransaction::findByVisit($visit) : null;

        if (! $transaction) {
            return response()->json(['error' => 'تراکنش یافت نشد.'], 404);
        }

        return response()->json([
            'visitId' => $transaction->visit_id,
            'status' => $transaction->status,
            'sku' => $transaction->sku,
            'purpose' => $transaction->purpose,
            'amount' => (int) $transaction->amount,
            'purchaseToken' => $transaction->purchase_token,
        ]);
    }

    private function redirect(BazaarIapTransaction $transaction, string $status, string $sku, string $returnUrl): string
    {
        return $this->bazaar->redirectUrl([
            'visit' => $transaction->visit_id,
            'status' => $status,
            'sku' => $sku,
            'return' => $returnUrl,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function appendReturn(string $url, array $params): string
    {
        $glue = str_contains($url, '?') ? '&' : '?';

        return $url.$glue.http_build_query($params);
    }

    /** Only allow returning to this application (relative path or same host). */
    private function safeReturn(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '/';
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? '/' : $url;
        }

        if (! preg_match('~^https?://~i', $url)) {
            return '/';
        }

        $allowedHosts = [request()->getHost()];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $allowedHosts[] = $appHost;
        }

        return in_array(parse_url($url, PHP_URL_HOST), $allowedHosts, true) ? $url : '/';
    }

    private function ensureEnabled(): void
    {
        if (! $this->bazaar->isEnabled()) {
            abort(503, 'درگاه پرداخت درون‌برنامه‌ای کافه‌بازار فعال نیست.');
        }
    }
}
