<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discount coupons — faithful port of couponsRouter in routes.ts.
 * Uses OrderController::evaluateCoupon for identical validation rules.
 */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Coupon::orderByDesc('expire_date')->get()->map([Formatter::class, 'coupon'])->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = self::validated($request);
        if (is_string($data)) {
            return response()->json(['error' => $data], 400);
        }
        if (Coupon::where('code', $data['code'])->exists()) {
            return response()->json(['error' => 'این کد تخفیف قبلاً ثبت شده است.'], 400);
        }

        $coupon = Coupon::create($data);

        return response()->json(Formatter::coupon($coupon), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $coupon = Coupon::find($id);
        if (! $coupon) {
            return response()->json(['error' => 'کد تخفیف یافت نشد.'], 404);
        }

        $data = self::validated($request, true);
        if (is_string($data)) {
            return response()->json(['error' => $data], 400);
        }
        $coupon->update($data);

        return response()->json(Formatter::coupon($coupon));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        Coupon::where('id', $id)->delete();

        return response()->json(['message' => 'کد تخفیف حذف شد.']);
    }

    /** Validate a coupon against a given total (used by the order form). */
    public function validate(Request $request): JsonResponse
    {
        $code = (string) ($request->json('code') ?? '');
        $total = (int) ($request->json('totalPrice') ?? 0);

        $result = OrderController::evaluateCoupon($code, $total);
        if (! $result['valid']) {
            return response()->json(['valid' => false, 'error' => $result['error']], 400);
        }

        return response()->json([
            'valid' => true,
            'discount' => $result['discount'],
            'coupon' => Formatter::coupon($result['coupon']),
        ]);
    }

    private static function validated(Request $request, bool $partial = false): array|string
    {
        $body = (array) $request->json()->all();

        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if ($code === '') {
            return 'کد تخفیف الزامی است.';
        }
        $percent = (int) ($body['percent'] ?? 0);
        if ($percent < 1 || $percent > 100) {
            return 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.';
        }

        return [
            'code' => $code,
            'percent' => $percent,
            'max_amount' => max(0, (int) ($body['maxAmount'] ?? 0)),
            'min_order_amount' => max(0, (int) ($body['minOrderAmount'] ?? 0)),
            'expire_date' => (string) ($body['expireDate'] ?? now()->toDateString()),
            'max_usage' => max(0, (int) ($body['maxUsage'] ?? 0)),
            'is_active' => (bool) ($body['isActive'] ?? true),
        ];
    }
}
