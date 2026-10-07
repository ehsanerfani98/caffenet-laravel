<?php

namespace App\Listeners;

use App\Models\Payment;
use App\Services\PaymentFulfillmentService;
use App\Services\SettingsService;
use Caffenet\BazaarIap\Events\PurchaseConsumed;
use Caffenet\BazaarIap\Events\PurchaseFailed;
use Illuminate\Support\Facades\Log;

/**
 * Bridges the package's Cafe Bazaar events into this application's payment
 * lifecycle: the visit id (stored as the payment `authority`) links a Bazaar
 * purchase to the order / wallet top-up it was created for.
 */
class FulfillBazaarPurchase
{
    public function handleConsumed(PurchaseConsumed $event): void
    {
        $transaction = $event->transaction;
        $payment = $this->resolvePayment($transaction->visit_id);

        if (! $payment) {
            Log::warning('Bazaar purchase consumed with no matching payment.', [
                'visit' => $transaction->visit_id,
                'token' => $event->purchaseToken,
            ]);

            return;
        }

        // Economic guard (docs «الزام پیادهسازی»): the purchased SKU is mapped
        // to a wallet amount via the business presets, and that amount must
        // equal the pending payment. A mismatch means the deep link and the SKU
        // price drifted apart, so the purchase is failed rather than crediting a
        // wrong balance. Unknown SKUs (no mapping) are skipped.
        $expected = SettingsService::walletTopUpAmountForSku((string) $transaction->sku);
        if ($expected !== null && $expected !== (int) $payment->amount) {
            Log::warning('Bazaar SKU amount mismatch — payment failed.', [
                'visit' => $transaction->visit_id,
                'sku' => $transaction->sku,
                'expected' => $expected,
                'payment' => (int) $payment->amount,
            ]);
            PaymentFulfillmentService::markFailed($payment, 'مبلغ SKU کافه‌بازار با مبلغ پرداخت مطابقت ندارد');

            return;
        }

        // Purchase token doubles as the gateway reference/tracking code.
        PaymentFulfillmentService::apply($payment, $event->purchaseToken);
    }

    public function handleFailed(PurchaseFailed $event): void
    {
        $visit = $event->transaction?->visit_id;

        if (! $visit) {
            return;
        }

        $payment = $this->resolvePayment($visit);

        if ($payment) {
            PaymentFulfillmentService::markFailed($payment, $event->reason);
        }
    }

    private function resolvePayment(string $visitId): ?Payment
    {
        return Payment::where('authority', $visitId)->where('gateway', 'bazaar')->first();
    }
}
