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
        // Expose the Cafe Bazaar in-app gateway snapshot (no secrets) so the
        // client knows whether to offer that payment method.
        $out['bazaar'] = SettingsService::bazaarIapSettings();
        // Expose the BazaarPay web gateway snapshot (no secrets) — the
        // WebView-compatible gateway (wallet top-up + Direct Pay). The
        // merchant token is stripped: it must never reach the client.
        $bazaarPay = SettingsService::bazaarPaySettings();
        unset($bazaarPay['token']);
        $out['bazaarPay'] = $bazaarPay;
        // Public read-only flag so the client can warn users when SMS is off.
        $out['smsEnabled'] = SettingsService::bool('smsEnabled');
        foreach (SettingsService::businessKeys() as $key) {
            $raw = SettingsService::raw($key);
            if (in_array($key, ['enableWalletPayment', 'enableCoupons'], true)) {
                $out[$key] = SettingsService::bool($key);
            } elseif ($key === 'walletTopUpPresets') {
                // Each preset carries its own Cafe Bazaar SKU ({amount, sku}).
                $out[$key] = SettingsService::walletTopUpPresets();
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

        foreach (SettingsService::businessKeys() as $key) {
            if (! array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if ($key === 'walletTopUpPresets') {
                // Normalise to [{amount, sku}] so the stored JSON shape stays
                // canonical regardless of what the client sends.
                $value = json_encode(
                    SettingsService::normalizeWalletTopUpPresets($value),
                    JSON_UNESCAPED_UNICODE
                );
            } elseif (is_array($value)) {
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
            if (($def['scope'] ?? SettingsService::SCOPE_SYSTEM) !== SettingsService::SCOPE_SYSTEM) {
                continue; // business keys are not writable through the system endpoint
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
