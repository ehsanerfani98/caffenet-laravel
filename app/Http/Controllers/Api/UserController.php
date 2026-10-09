<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\UserPurgeService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * User management — faithful port of usersRouter in routes.ts.
 * PATCH handles role/block/wallet/permissions guarded by the matching
 * permissions (users.edit | users.permissions). Roles and permissions are
 * applied exclusively through spatie (User::syncAccess).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::orderByDesc('created_at')->get();

        return response()->json($users->map([Formatter::class, 'user'])->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $phone = AuthService::normalizePhone((string) ($body['phone'] ?? ''));

        if (! AuthService::isValidIranianMobile($phone)) {
            return response()->json(['error' => 'شماره موبایل معتبر نیست.'], 400);
        }
        if (User::where('phone', $phone)->exists()) {
            return response()->json(['error' => 'کاربری با این شماره وجود دارد.'], 400);
        }

        $role = in_array($body['role'] ?? '', ['customer', 'operator', 'admin'], true) ? $body['role'] : 'customer';

        $user = User::create([
            'phone' => $phone,
            'name' => trim((string) ($body['name'] ?? '')) ?: 'کاربر '.substr($phone, -4),
            'role' => $role,
            'wallet_balance' => 0,
            'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);
        $user->syncAccess($role, PermissionService::normalize($body['permissions'] ?? null));

        return response()->json(Formatter::user($user), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $body = (array) $request->json()->all();
        $actor = $request->user();
        $hasEdit = $actor->hasAnyPermission(['users.edit', 'users.manage']);
        $hasPerm = $actor->hasAnyPermission(['users.permissions', 'users.manage']);
        if (! $hasEdit && ! $hasPerm) {
            return response()->json(['error' => 'شما دسترسی لازم برای این عملیات را ندارید.'], 403);
        }

        DB::transaction(function () use ($user, $body, $hasEdit, $hasPerm, $actor) {
            $role = $user->role;
            if ($hasEdit) {
                if (isset($body['role']) && in_array($body['role'], ['customer', 'operator', 'admin'], true)) {
                    $role = $body['role'];
                }
                if (array_key_exists('isBlocked', $body)) {
                    $user->is_blocked = (bool) $body['isBlocked'];
                }
                if (array_key_exists('name', $body) && trim((string) $body['name']) !== '') {
                    $user->name = trim((string) $body['name']);
                }
                // walletChange: signed delta — the ONLY admin path for balance edits.
                // Each change is written to the wallet ledger with the acting admin
                // so every manual credit/debit is traceable.
                if (isset($body['walletChange']) && is_numeric($body['walletChange'])) {
                    $delta = (int) $body['walletChange'];
                    if ($delta > 0) {
                        WalletService::credit($user, $delta, WalletTransaction::TYPE_MANUAL_CREDIT, [
                            'note' => 'تنظیم موجودی توسط مدیریت',
                            'performedById' => $actor->id,
                            'performedByName' => $actor->name,
                        ]);
                    } elseif ($delta < 0) {
                        // A manual deduction never pushes the wallet below zero.
                        $available = (int) DB::table('users')->where('id', $user->id)->value('wallet_balance');
                        $amount = min(abs($delta), $available);
                        if ($amount > 0) {
                            WalletService::debit($user, $amount, WalletTransaction::TYPE_MANUAL_DEBIT, [
                                'note' => 'کسر موجودی توسط مدیریت',
                                'performedById' => $actor->id,
                                'performedByName' => $actor->name,
                            ]);
                        }
                    }
                }
            }

            // Roles/permissions via spatie. An explicit, non-empty override
            // replaces the role's default set; an empty/absent one restores
            // the role defaults. Admins always get the full catalog.
            if ($hasPerm && array_key_exists('permissions', $body)) {
                $override = PermissionService::normalize($body['permissions']);
            } else {
                // Preserve the existing override (direct spatie permissions
                // only — role-synced defaults are not an override).
                $direct = $user->permissions->pluck('name')->all();
                $override = count($direct) ? PermissionService::normalize($direct) : null;
            }

            $user->syncAccess($role, $role === 'admin' ? null : $override);
        });

        // Reflect the ledger-driven balance in the response (the model instance
        // may still hold the pre-change value).
        $user->refresh();

        return response()->json(Formatter::user($user));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $me = $request->attributes->get('authUser');
        if ($me->id === $user->id) {
            return response()->json(['error' => 'حذف حساب خودتان ممکن نیست.'], 400);
        }

        // Hard-delete the account together with every row that belongs to it
        // (orders + their logs/tickets/payments, wallet payments, wallet ledger,
        // and the phone-bound OTP/SMS artefacts). Orders the user handled as an
        // operator are kept but unassigned.
        UserPurgeService::purge($user);

        return response()->json(['message' => 'کاربر و همه داده‌های مرتبط حذف شد.']);
    }
}
