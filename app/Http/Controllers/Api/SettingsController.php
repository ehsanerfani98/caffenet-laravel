<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings endpoints — business (public read / settings.edit write) and
 * system (masked secrets, boot-only read-only), matching settingsRouter.
 */
class SettingsController extends Controller
{
    public function business(): JsonResponse
    {
        $out = [];
        foreach (SettingsService::BUSINESS_KEYS as $key) {
            $raw = SettingsService::raw($key);
            if (in_array($key, ['enableWalletPayment', 'enableCoupons', 'otpTestMode'], true)) {
                $out[$key] = SettingsService::bool($key);
            } elseif ($key === 'walletTopUpPresets') {
                $decoded = json_decode((string) $raw, true);
                $out[$key] = is_array($decoded) ? $decoded : [50000, 100000, 200000, 500000];
            } elseif (in_array($key, ['fileRetentionDays', 'courierCost'], true)) {
                $out[$key] = (int) $raw;
            } elseif (in_array($key, ['urgentPriceMultiplier', 'immediatePriceMultiplier'], true)) {
                $out[$key] = (float) $raw;
            } else {
                $out[$key] = (string) $raw;
            }
        }

        return response()->json($out);
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();

        foreach (SettingsService::BUSINESS_KEYS as $key) {
            if (! array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else {
                $value = (string) $value;
            }
            SettingsService::set($key, $value);
        }

        return response()->json(['message' => 'تنظیمات ذخیره شد.']);
    }

    public function system(): JsonResponse
    {
        return response()->json(['settings' => SettingsService::systemSettingsView()]);
    }

    public function updateSystem(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $incoming = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        foreach ($incoming as $key => $value) {
            $def = null;
            foreach (SettingsService::DEFS as $d) {
                if ($d['key'] === $key) {
                    $def = $d;
                    break;
                }
            }
            if (! $def || ($def['bootOnly'] ?? false)) {
                continue; // unknown or read-only keys are silently skipped
            }
            if ($def['type'] === 'boolean') {
                $value = ($value === true || $value === '1' || $value === 1) ? '1' : '0';
            } elseif (! is_string($value)) {
                $value = (string) $value;
            }
            SettingsService::applyUpdate($key, (string) $value);
        }

        return response()->json(['message' => 'تنظیمات سیستمی ذخیره شد.']);
    }
}
