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
use Illuminate\Support\Facades\DB;

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
        // Admins are never lockable out of the ledger, and staff holding
        // wallet.view|wallet.manage may inspect any (or all) users' ledgers.
        // The role check keeps an admin whose spatie role is momentarily
        // missing the wallet.* keys from being pinned to their own (empty)
        // ledger — which is what produced a false "no transactions found".
        $canViewAll = $user->role === 'admin'
            || $user->hasAnyPermission(['wallet.view', 'wallet.manage']);

        $query = WalletTransaction::query();

        if ($canViewAll) {
            $targetId = (string) ($request->query('userId') ?? '');
            if ($targetId !== '') {
                $query->where('user_id', $targetId);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        // Optional filters — meaningful for staff; a customer's view is always
        // pinned to their own ledger by the branch above.
        $type = (string) ($request->query('type') ?? '');
        if ($type !== '' && array_key_exists($type, WalletTransaction::TYPE_TITLES)) {
            $query->where('type', $type);
        }

        $direction = (string) ($request->query('direction') ?? '');
        if (in_array($direction, [WalletTransaction::DIRECTION_CREDIT, WalletTransaction::DIRECTION_DEBIT], true)) {
            $query->where('direction', $direction);
        }

        // `created_at` is an ISO-8601 string, so lexicographic comparison is a
        // valid chronological comparison.
        $from = trim((string) ($request->query('from') ?? ''));
        if ($from !== '') {
            $query->where('created_at', '>=', $from);
        }

        $to = trim((string) ($request->query('to') ?? ''));
        if ($to !== '') {
            $query->where('created_at', '<=', $to);
        }

        $search = trim((string) ($request->query('search') ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('reference', 'like', $like)
                    ->orWhere('note', 'like', $like)
                    ->orWhere('user_id', 'like', $like)
                    ->orWhere('performed_by_name', 'like', $like);
            });
        }

        $limit = (int) ($request->query('limit') ?? 200);
        $limit = max(1, min(500, $limit));

        $rows = $query->orderByDesc('created_at')->limit($limit)->get();

        return response()->json($rows->map([Formatter::class, 'walletTransaction'])->values()->all());
    }

    /**
     * Staff roster for the "User Transactions" tab: every user that has at
     * least one wallet-ledger entry, with a compact financial summary so the
     * admin can pick a customer and open the full, filterable history.
     * Guarded by wallet.users|wallet.manage at the route level.
     */
    public function users(Request $request): JsonResponse
    {
        $search = trim((string) ($request->query('search') ?? ''));

        $agg = DB::table('wallet_transactions')
            ->selectRaw('user_id, COUNT(*) as txn_count, MAX(created_at) as last_txn_at')
            ->selectRaw(
                'SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as total_credit',
                [WalletTransaction::DIRECTION_CREDIT]
            )
            ->selectRaw(
                'SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as total_debit',
                [WalletTransaction::DIRECTION_DEBIT]
            )
            ->groupBy('user_id');

        $query = User::query()
            ->joinSub($agg, 'wtx', function ($join) {
                $join->on('wtx.user_id', '=', 'users.id');
            })
            ->select([
                'users.id', 'users.name', 'users.phone', 'users.role', 'users.wallet_balance',
                'wtx.txn_count', 'wtx.total_credit', 'wtx.total_debit', 'wtx.last_txn_at',
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)
                    ->orWhere('users.phone', 'like', $like);
            });
        }

        $rows = $query->orderByDesc('wtx.last_txn_at')->limit(500)->get();

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'phone' => $r->phone,
            'role' => $r->role,
            'walletBalance' => (int) $r->wallet_balance,
            'txnCount' => (int) $r->txn_count,
            'totalCredit' => (int) $r->total_credit,
            'totalDebit' => (int) $r->total_debit,
            'lastTransactionAt' => $r->last_txn_at,
        ])->values()->all());
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

        // SMS: pattern-based — uses smsPatternRefund + smsPatternRefundVars.
        SmsService::sendOrderNotification(
            $target->phone,
            'refund',
            [
                'amount'         => (string) $amount,
                'customer_name'  => $target->name,
                'customer_phone' => $target->phone,
            ]
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
