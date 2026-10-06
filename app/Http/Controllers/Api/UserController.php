<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * User management — faithful port of usersRouter in routes.ts.
 * PATCH handles role/block/wallet/permissions guarded by the matching
 * permissions (users.edit | users.permissions).
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
        $phone = \App\Services\AuthService::normalizePhone((string) ($body['phone'] ?? ''));

        if (! \App\Services\AuthService::isValidIranianMobile($phone)) {
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
            'permissions_json' => isset($body['permissions']) ? json_encode($body['permissions']) : null,
            'created_at' => now()->toIso8601String(),
        ]);

        return response()->json(Formatter::user($user), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $body = (array) $request->json()->all();
        $granted = $request->attributes->get('authPermissions');
        $hasEdit = PermissionService::has($granted, 'users.edit');
        $hasPerm = PermissionService::has($granted, 'users.permissions');
        if (! $hasEdit && ! $hasPerm) {
            return response()->json(['error' => 'شما دسترسی لازم برای این عملیات را ندارید.'], 403);
        }

        DB::transaction(function () use ($user, $body, $hasEdit, $hasPerm, $request) {
            if ($hasEdit) {
                if (isset($body['role']) && in_array($body['role'], ['customer', 'operator', 'admin'], true)) {
                    $user->role = $body['role'];
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
            if ($hasPerm && array_key_exists('permissions', $body)) {
                $permissions = is_array($body['permissions']) ? $body['permissions'] : [];
                $valid = array_flip(PermissionService::allKeys());
                $clean = array_values(array_filter($permissions, fn ($p) => is_string($p) && isset($valid[$p])));
                $user->permissions_json = count($clean) ? json_encode($clean) : null;
            }
            $user->save();
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

        $user->delete();

        return response()->json(['message' => 'کاربر حذف شد.']);
    }
}
