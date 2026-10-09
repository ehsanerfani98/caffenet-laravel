<?php

namespace Tests\Feature;

use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the Zarinpal v4 contract against regressions:
 *  - the StartPay redirect must include the `/pg` segment;
 *  - `currency` must be sent so Toman amounts are not read as Rial;
 *  - sandbox uses sandbox.zarinpal.com and a stable UUID merchant id shared
 *    by request and verify;
 *  - verify treats 100 and 101 as success and surfaces error codes;
 *  - real mode rejects a relative callback URL.
 */
class ZarinpalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://app.test/api/payments/verify';

    private const MERCHANT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const AUTHORITY = 'A0000000000000000000000000000wwOGYpd';

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::set('paymentEnabled', '1');
        SettingsService::set('paymentTestMode', '0');
        SettingsService::set('paymentSandbox', '0');
        SettingsService::set('paymentMerchantId', self::MERCHANT);
        SettingsService::set('paymentCallbackUrl', self::CALLBACK);
        // paymentCurrency (IRT) and paymentAutoVerify (1) fall back to defaults.
    }

    public function test_real_request_hits_v4_endpoint_and_builds_pg_startpay_redirect(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['code' => 100, 'message' => 'Success', 'authority' => self::AUTHORITY, 'fee_type' => 'Merchant', 'fee' => 100],
                'errors' => [],
            ], 200),
        ]);

        $result = PaymentService::requestPayment(50000, 'شارژ کیف پول', self::CALLBACK, '09120000000', 'pay-abc');

        $this->assertTrue($result['ok']);
        $this->assertSame(self::AUTHORITY, $result['authority']);
        $this->assertSame(
            'https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY,
            $result['redirectUrl']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://payment.zarinpal.com/pg/v4/payment/request.json'
                && ($body['merchant_id'] ?? null) === self::MERCHANT
                && ($body['amount'] ?? null) === 50000
                && ($body['currency'] ?? null) === 'IRT'
                && ($body['callback_url'] ?? null) === self::CALLBACK
                && ($body['metadata']['auto_verify'] ?? null) === true
                && ($body['metadata']['order_id'] ?? null) === 'pay-abc'
                && ($body['metadata']['mobile'] ?? null) === '09120000000';
        });
    }

    public function test_sandbox_request_uses_sandbox_domain_and_reuses_generated_merchant_id(): void
    {
        SettingsService::set('paymentSandbox', '1');
        SettingsService::set('paymentMerchantId', ''); // sandbox accepts any UUID

        $sandboxAuthority = 'S0000000000000000000000000000wwOGYpd';

        Http::fake([
            '*' => Http::response([
                'data' => ['code' => 100, 'message' => 'Success', 'authority' => $sandboxAuthority],
                'errors' => [],
            ], 200),
        ]);

        $result = PaymentService::requestPayment(10000, 'شارژ کیف پول', self::CALLBACK, '09120000000', 'pay-abc');

        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('https://sandbox.zarinpal.com/pg/StartPay/', (string) $result['redirectUrl']);

        $sentMerchant = null;
        Http::assertSent(function ($request) use (&$sentMerchant) {
            $sentMerchant = $request->data()['merchant_id'] ?? null;

            return $request->url() === 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';
        });

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $sentMerchant);
        $this->assertSame($sentMerchant, SettingsService::raw('paymentSandboxMerchantId'));

        // Verify must present the exact same terminal id as the request.
        Http::fake([
            '*' => Http::response([
                'data' => ['code' => 100, 'message' => 'Verified', 'ref_id' => 201, 'card_pan' => '502229******5995'],
                'errors' => [],
            ], 200),
        ]);

        $verify = PaymentService::verifyPayment($sandboxAuthority, 10000);
        $this->assertTrue($verify['ok']);

        Http::assertSent(function ($request) use ($sentMerchant) {
            return $request->url() === 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
                && ($request->data()['merchant_id'] ?? null) === $sentMerchant;
        });
    }

    public function test_verify_treats_101_as_success_and_returns_reference(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['code' => 101, 'message' => 'Verified', 'ref_id' => 201, 'card_pan' => '502229******5995'],
                'errors' => [],
            ], 200),
        ]);

        $result = PaymentService::verifyPayment(self::AUTHORITY, 50000);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already']);
        $this->assertSame('201', $result['refId']);
        $this->assertSame('502229******5995', $result['cardPan']);
    }

    public function test_verify_reports_error_code_on_amount_mismatch(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'errors' => ['code' => -50, 'message' => 'Session is not valid, amounts values is not the same.'],
            ], 200),
        ]);

        $result = PaymentService::verifyPayment(self::AUTHORITY, 50000);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('-50', (string) $result['error']);
    }

    public function test_real_request_rejects_relative_callback_url(): void
    {
        Http::fake();

        $result = PaymentService::requestPayment(50000, 'شارژ کیف پول', '/api/payments/verify', '09120000000', 'pay-abc');

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }
}
