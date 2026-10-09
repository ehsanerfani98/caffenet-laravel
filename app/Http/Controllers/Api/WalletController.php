<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SmsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallet ledger + refunds.
 *
 *  - transactions: a customer sees their own ledger; staff holding
 *    wallet.view|wallet.manage may inspect any (or all) users' ledgers.
 *  - refund:        admin/manager only (wallet.refund|wallet.manage) — credits
 *    the customer's wallet, optionally against an order which is then moved to
 *    the `refunded` status.
 *  - adjust:        admin/manager only (wallet.adjust|wallet.manage) — a manual
 *    credit or debit with a reason, recorded in the ledger.
 */
class WalletController extends Controller
{
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $canViewAll = $user->hasAnyPermission(['wallet.view', 'wallet.manage']);

        $query = WalletTransaction::query();

        if ($canViewAll) {
            $targetId = (string) ($request->query('userId') ?? '');
            if ($targetId !== '') {
                $query->where('user_id', $targetId);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        $limit = (int) ($request->query('limit') ?? 200);
        $limit = max(1, min(500, $limit));

        $rows = $query->orderByDesc('created_at')->limit($limit)->get();

        return response()->json($rows->map([Formatter::class, 'walletTransaction'])->values()->all());
    }

    public function refund(Request $request): JsonResponse
    {
        $actor = $request->user();
        $body = (array) $request->json()->all();

        $target = User::find((string) ($body['userId'] ?? ''));
        if (! $target) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $amount = (int) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            return response()->json(['error' => 'مبلغ عودت باید بزرگ‌تر از صفر باشد.'], 400);
        }

        $orderId = isset($body['orderId']) && $body['orderId'] !== '' ? (string) $body['orderId'] : null;
        $order = null;
        if ($orderId !== null) {
            $order = Order::find($orderId);
            if (! $order) {
                return response()->json(['error' => 'سفارش یافت نشد.'], 404);
            }
            if ($order->customer_id !== $target->id) {
                return response()->json(['error' => 'این سفارش به کاربر انتخاب‌شده تعلق ندارد.'], 400);
            }
        }

        $reason = trim((string) ($body['reason'] ?? ''));

        $txn = WalletService::refund($target, $amount, [
            'orderId' => $order?->id,
            'note' => $reason !== '' ? $reason : 'عودت وجه به کیف پول مشتری',
            'performedById' => $actor?->id,
            'performedByName' => $actor?->name,
        ]);

        SmsService::sendNotification(
            $target->phone,
            'کافی‌نت احسان: مبلغ '.number_format($amount).' تومان به کیف پول شما عودت داده شد.',
            'order_status'
        );

        return response()->json([
            'transaction' => Formatter::walletTransaction($txn),
            'user' => Formatter::user($target->fresh()),
            'order' => $order ? Formatter::order($order->fresh()) : null,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $actor = $request->user();
        $body = (array) $request->json()->all();

        $target = User::find((string) ($body['userId'] ?? ''));
        if (! $target) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $amount = (int) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            return response()->json(['error' => 'مبلغ باید بزرگ‌تر از صفر باشد.'], 400);
        }

        $direction = ($body['direction'] ?? 'credit') === 'debit'
            ? WalletTransaction::DIRECTION_DEBIT
            : WalletTransaction::DIRECTION_CREDIT;
        $reason = trim((string) ($body['reason'] ?? 'تنظیم دستی توسط مدیریت'));
        $opts = [
            'note' => $reason !== '' ? $reason : 'تنظیم دستی توسط مدیریت',
            'performedById' => $actor?->id,
            'performedByName' => $actor?->name,
        ];

        try {
            if ($direction === WalletTransaction::DIRECTION_CREDIT) {
                $txn = WalletService::credit($target, $amount, WalletTransaction::TYPE_MANUAL_CREDIT, $opts);
            } else {
                $txn = WalletService::debit($target, $amount, WalletTransaction::TYPE_MANUAL_DEBIT, $opts);
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_WALLET_BALANCE') {
                return response()->json(['error' => 'موجودی کیف پول کاربر برای این کسر کافی نیست.'], 400);
            }
            throw $e;
        }

        return response()->json([
            'transaction' => Formatter::walletTransaction($txn),
            'user' => Formatter::user($target->fresh()),
        ]);
    }
}
