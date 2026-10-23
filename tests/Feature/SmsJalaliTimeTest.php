<?php

namespace Tests\Feature;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\JalaliDate;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «زمان شروع» در پیامک‌ها باید شمسی و با ارقام فارسی باشد.
 *
 * Before this fix the SMS variable start_time carried the raw ISO-8601
 * UTC timestamp (e.g. 2026-10-23T12:03:40+00:00) — unreadable for
 * customers. JalaliDate::format now converts every value to the
 * Asia/Tehran timezone and the Jalali (Shamsi) calendar with Persian
 * digits (۱۴۰۵/۸/۱ ۱۵:۳۳) inside NotificationDispatcherService's data
 * pool, so BOTH SMS types that declare the start_time field inherit the
 * fix: order_started (شروع سفارش) and reminder (یادآوری نزدیک شروع).
 * end_time gets the same treatment for future patterns.
 *
 * The conversion uses the standard public-domain Gregorian→Jalali
 * algorithm (no intl extension needed) and must never throw — a
 * malformed timestamp falls back to the original value instead of
 * breaking the SMS chain.
 */
class SmsJalaliTimeTest extends TestCase
{
    use RefreshDatabase;

    /** UTC timestamp whose Tehran/Jalali rendering is fully known. */
    private const FIXED_UTC = '2026-10-23T12:03:40+00:00'; // → ۱۴۰۵/۸/۱ ۱۵:۳۳

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        // SMS system fully configured and live (real edge.ippanel HTTP call,
        // faked below) so every assertion targets the actual send path.
        SettingsService::set('smsEnabled', '1');
        SettingsService::set('smsNotifyEnabled', '1');
        SettingsService::set('smsTestMode', '0');
        SettingsService::set('smsApiKey', 'test-api-key');
        SettingsService::set('smsSender', '09120000000');

        Http::fake([
            'edge.ippanel.com/*' => Http::response([
                'meta' => ['status' => true, 'message' => 'ok'],
                'data' => ['message_outbox_ids' => [12345]],
            ], 200),
        ]);
    }

    // -------------------------------------------------------------
    // Unit level — the conversion itself
    // -------------------------------------------------------------

    public function test_gregorian_to_jalali_known_anchors(): void
    {
        // Nowruz dates and the 1403 leap day are hard, well-known facts.
        $anchors = [
            ['2026-03-21', [1405, 1, 1]],  // Nowruz 1405
            ['2025-03-21', [1404, 1, 1]],  // Nowruz 1404
            ['2024-03-20', [1403, 1, 1]],  // Nowruz 1403
            ['2025-03-20', [1403, 12, 30]], // leap day of 1403
            ['2026-10-23', [1405, 8, 1]],  // the exact customer-reported date
            ['2026-03-20', [1404, 12, 29]], // last non-leap day of 1404
            ['2027-01-21', [1405, 11, 1]],
        ];

        foreach ($anchors as [$gregorian, $expected]) {
            [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorian));
            $this->assertSame(
                $expected,
                JalaliDate::toJalali($gy, $gm, $gd),
                "Gregorian $gregorian must map to [" . implode(',', $expected) . ']'
            );
        }
    }

    public function test_format_produces_jalali_tehran_persian_digits(): void
    {
        // 12:03:40 UTC = 15:33:40 Tehran (fixed +03:30, no DST since 2022)
        // 2026-10-23 = 1405/8/1
        $this->assertSame('۱۴۰۵/۸/۱ ۱۵:۳۳', JalaliDate::format(self::FIXED_UTC));

        // DateTimeInterface input goes through the same path
        $this->assertSame(
            '۱۴۰۵/۸/۱ ۱۵:۳۳',
            JalaliDate::format(new \DateTimeImmutable(self::FIXED_UTC))
        );

        // Offset-less strings are assumed UTC (the app timezone)
        $this->assertSame('۱۴۰۵/۸/۱ ۱۵:۳۳', JalaliDate::format('2026-10-23 12:03:40'));

        // A date near midnight rolls over correctly (20:30 UTC Mar 20 = 00:00
        // Tehran Mar 21 = Nowruz 1405)
        $this->assertSame('۱۴۰۵/۱/۱ ۰۰:۰۰', JalaliDate::format('2026-03-20T20:30:00+00:00'));
    }

    public function test_format_degenerate_inputs_never_throw(): void
    {
        // Empty values render as an empty SMS variable, exactly like the
        // previous ?? '' behaviour
        $this->assertSame('', JalaliDate::format(null));
        $this->assertSame('', JalaliDate::format(''));

        // Unparseable garbage is passed through untouched — the SMS still
        // goes out with SOMETHING instead of exploding the order flow
        $this->assertSame('not-a-date', JalaliDate::format('not-a-date'));
    }

    public function test_digits_converts_latin_to_persian(): void
    {
        $this->assertSame('۱۴۰۵/۸/۱', JalaliDate::digits('1405/8/1'));
        $this->assertSame('۵ دقیقه', JalaliDate::digits('5 دقیقه'));
        // Persian digits stay untouched
        $this->assertSame('۱۴۰۵', JalaliDate::digits('۱۴۰۵'));
    }

    // -------------------------------------------------------------
    // Integration level — the SMS chain carries Jalali start_time
    // -------------------------------------------------------------

    public function test_reminder_sms_sends_jalali_start_time(): void
    {
        $this->configureReminderPattern();
        $order = $this->createQueuedOrder();

        app(NotificationDispatcherServiceInterface::class)->sendStartReminder($order, 5);

        Http::assertSent(function ($request) {
            if (! str_starts_with((string) $request->url(), 'https://edge.ippanel.com')) {
                return false;
            }
            $params = $request->data()['params'] ?? [];

            // The customer-facing value: Jalali, Tehran time, Persian digits
            $this->assertSame('۱۴۰۵/۸/۱ ۱۵:۳۳', $params['shuru'] ?? null);
            $this->assertSame('CN-2610-2496', $params['order'] ?? null);
            $this->assertSame('5', $params['min_left'] ?? null);

            // The raw ISO-8601 UTC string must NEVER leak into the SMS
            $this->assertStringNotContainsString('2026-10-23T12', json_encode($request->data(), JSON_UNESCAPED_UNICODE));

            return true;
        });

        $this->assertSame(
            1,
            SmsLog::where('type', 'reminder')->where('status', 'sent')->count(),
            'the reminder SMS must be logged as sent'
        );
    }

    public function test_order_started_sms_sends_jalali_start_time(): void
    {
        $this->configureStartedPattern();
        $order = $this->createQueuedOrder();

        app(NotificationDispatcherServiceInterface::class)->notifyOrderStarted($order);

        Http::assertSent(function ($request) {
            if (! str_starts_with((string) $request->url(), 'https://edge.ippanel.com')) {
                return false;
            }
            $params = $request->data()['params'] ?? [];

            $this->assertSame('۱۴۰۵/۸/۱ ۱۵:۳۳', $params['shuru'] ?? null);
            $this->assertSame('CN-2610-2496', $params['order'] ?? null);
            $this->assertStringNotContainsString('2026-10-23T12', json_encode($request->data(), JSON_UNESCAPED_UNICODE));

            return true;
        });

        $this->assertSame(
            1,
            SmsLog::where('type', 'order_started')->where('status', 'sent')->count()
        );
    }

    public function test_missing_start_time_renders_empty_variable(): void
    {
        $this->configureReminderPattern();
        $order = $this->createQueuedOrder(null);

        app(NotificationDispatcherServiceInterface::class)->sendStartReminder($order, 5);

        Http::assertSent(function ($request) {
            if (! str_starts_with((string) $request->url(), 'https://edge.ippanel.com')) {
                return false;
            }
            $params = $request->data()['params'] ?? [];
            // Unset ETA renders as an empty string, same as the old ?? ''
            $this->assertSame('', $params['shuru'] ?? null);

            return true;
        });
    }

    // -------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------

    private function configureReminderPattern(): void
    {
        SettingsService::set('smsPatternReminder', 'code-rem');
        SettingsService::set('smsPatternReminder_order_number', 'order');
        SettingsService::set('smsPatternReminder_customer_name', 'cust');
        SettingsService::set('smsPatternReminder_minutes_before', 'min_left');
        SettingsService::set('smsPatternReminder_start_time', 'shuru');
    }

    private function configureStartedPattern(): void
    {
        SettingsService::set('smsPatternStarted', 'code-start');
        SettingsService::set('smsPatternStarted_order_number', 'order');
        SettingsService::set('smsPatternStarted_start_time', 'shuru');
    }

    /**
     * Insert a queued order directly with a FIXED estimated_start_at so the
     * rendered Jalali value is deterministic regardless of test runtime.
     */
    private function createQueuedOrder(?string $estimatedStartAt = self::FIXED_UTC): Order
    {
        $customer = User::create([
            'id' => 'usr-cust-jl',
            'phone' => '09120000001',
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);

        $order = Order::create([
            'id' => 'ord-jalali-1',
            'order_number' => 'CN-2610-2496',
            'service_id' => 'srv-x',
            'service_title' => 'خدمت تست',
            'customer_id' => $customer->id,
            'customer_phone' => '09120000001',
            'customer_name' => 'مشتری تست',
            'status' => 'processing',
            'urgency' => 'normal',
            'delivery_method' => 'download',
            'form_data_json' => '[]',
            'customer_files_json' => '[]',
            'result_files_json' => '[]',
            'total_price' => 90000,
            'payment_method' => 'request',
            'estimated_start_at' => $estimatedStartAt,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        DB::table('categories')->insert([
            'id' => 'cat-x', 'title' => 'دسته تست', 'icon_name' => 'Printer',
        ]);
        DB::table('services')->insert([
            'id' => 'srv-x', 'category_id' => 'cat-x', 'title' => 'خدمت تست',
            'base_price' => 90000, 'is_active' => 1, 'fields_json' => '[]',
        ]);

        return $order;
    }
}
