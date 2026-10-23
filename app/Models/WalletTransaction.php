<?php

namespace App\Models;

/**
 * WalletTransaction — an immutable ledger entry for every money movement on a
 * user's wallet: gateway top-up, service purchase (wallet debit), refund, and
 * manual credit/debit performed by management. A `manual_payment` row records
 * an order payment the management registered offline (cash / terminal /
 * transfer): the wallet balance itself is untouched — direction stays `debit`
 * and `balance_after` equals the pre-existing balance — but the payment shows
 * up in the customer's ledger and the admin's user-transactions roster like
 * any other transaction.
 *
 * `amount` is always a positive magnitude; `direction` tells whether the money
 * came in (credit) or went out (debit). `balance_after` is the wallet balance
 * snapshot right after this movement, so the whole history is reconcilable.
 */
class WalletTransaction extends CaffenetModel
{
    protected const ID_PREFIX = 'wtx';

    protected $table = 'wallet_transactions';

    public const TYPE_TOPUP = 'topup';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_REFUND = 'refund';

    public const TYPE_MANUAL_CREDIT = 'manual_credit';

    public const TYPE_MANUAL_DEBIT = 'manual_debit';

    public const TYPE_MANUAL_PAYMENT = 'manual_payment';

    public const TYPE_TITLES = [
        'topup' => 'شارژ کیف پول (درگاه)',
        'purchase' => 'پرداخت خدمت',
        'refund' => 'عودت وجه',
        'manual_credit' => 'شارژ دستی توسط مدیریت',
        'manual_debit' => 'کسر دستی توسط مدیریت',
        'manual_payment' => 'پرداخت دستی سفارش (ثبت توسط مدیریت)',
    ];

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    protected $fillable = [
        'id', 'user_id', 'order_id', 'payment_id', 'type', 'direction', 'amount',
        'balance_after', 'reference', 'note', 'performed_by_id', 'performed_by_name',
        'metadata_json', 'created_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function typeTitle(): string
    {
        return self::TYPE_TITLES[$this->type] ?? $this->type;
    }

    /** Decoded metadata bag (gateway, sku, …); empty array when absent/invalid. */
    public function metadata(): array
    {
        $decoded = json_decode((string) $this->metadata_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
