<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PermissionService;
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

        DB::transaction(function () use ($user, $body, $hasEdit, $hasPerm) {
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
                if (isset($body['walletChange']) && is_numeric($body['walletChange'])) {
                    $delta = (int) $body['walletChange'];
                    $newBalance = max(0, (int) $user->wallet_balance + $delta);
                    $user->wallet_balance = $newBalance;
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

        // Detach spatie pivots before dropping the account.
        $user->syncRoles([]);
        $user->syncPermissions([]);
        $user->delete();

        return response()->json(['message' => 'کاربر حذف شد.']);
    }
}
