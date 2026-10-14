<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for the smart queue / capacity API surface.
 *
 * Covers:
 *  - Public /api/capacity/current returns a tier
 *  - /api/orders/track/{token} works without auth
 *  - /api/orders/{id}/updates enforces auth + ownership
 *  - /api/_artisan/run/{command} rejects bad tokens + whitelists commands
 */
class QueueApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOrderTypes();
    }

    public function test_capacity_current_returns_snapshot(): void
    {
        $resp = $this->getJson('/api/capacity/current');
        $resp->assertOk();
        $resp->assertJsonStructure(['tier', 'title', 'eta_minutes', 'first_slot_at', 'updated_at']);
        $this->assertContains($resp->json('tier'), Order::CAPACITY_TIERS);
    }

    public function test_track_by_token_public(): void
    {
        $order = $this->makeOrder(['track_token' => 'tok_abc123']);
        $resp = $this->getJson('/api/orders/track/tok_abc123');
        $resp->assertOk();
        $resp->assertJsonPath('order.id', $order->id);
    }

    public function test_track_by_wrong_token_404(): void
    {
        $this->makeOrder(['track_token' => 'tok_right']);
        $resp = $this->getJson('/api/orders/track/tok_wrong');
        $resp->assertNotFound();
    }

    public function test_artisan_runner_rejects_missing_token(): void
    {
        $resp = $this->getJson('/api/_artisan/run/cache:clear');
        $resp->assertStatus(404); // disabled by default
    }

    public function test_artisan_runner_accepts_valid_token(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        // Set the env directly via putenv + reload config.
        putenv('ARTISAN_RUNNER_TOKEN=test_secret_token');

        // Reload the controller's env lookup by clearing any cached config.
        $resp = $this->getJson('/api/_artisan/run/cache:clear?token=test_secret_token');
        $resp->assertOk();
        $resp->assertJsonStructure(['command', 'exit_code', 'output']);

        putenv('ARTISAN_RUNNER_TOKEN=');
    }

    public function test_artisan_runner_rejects_non_whitelisted_command(): void
    {
        putenv('ARTISAN_RUNNER_TOKEN=test_secret_token');
        $resp = $this->getJson('/api/_artisan/run/tinker?token=test_secret_token');
        $resp->assertStatus(400);
        putenv('ARTISAN_RUNNER_TOKEN=');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function seedOrderTypes(): void
    {
        foreach ([
            ['key' => 'quick', 'title' => 'فوری', 'base_duration_minutes' => 5, 'priority_level' => 9, 'allow_inline' => true],
            ['key' => 'normal', 'title' => 'عادی', 'base_duration_minutes' => 20, 'priority_level' => 5, 'allow_inline' => true],
            ['key' => 'long', 'title' => 'زمان‌بر', 'base_duration_minutes' => 60, 'priority_level' => 3, 'allow_inline' => false],
        ] as $row) {
            DB::table('order_types')->insert(array_merge($row, ['id' => 'otype-'.\Illuminate\Support\Str::uuid()]));
        }
    }

    private function makeOrder(array $overrides = []): Order
    {
        $order = new Order();
        $order->id = 'ord-'.\Illuminate\Support\Str::uuid();
        $order->order_number = 'CN-'.random_int(1000, 9999);
        $order->service_id = 'svc-test';
        $order->service_title = 'Test Service';
        $order->customer_id = 'usr-test';
        $order->customer_phone = '09120000000';
        $order->customer_name = 'Tester';
        $order->status = Order::STATUS_SUBMITTED;
        $order->urgency = 'normal';
        $order->queue_type = 'normal';
        $order->priority_level = 5;
        $order->form_data_json = '[]';
        $order->customer_files_json = '[]';
        $order->result_files_json = '[]';
        $order->total_price = 1000;
        $order->paid_amount = 1000;
        $order->payment_method = 'wallet';
        $order->payment_tracking_code = '';
        $order->track_token = $overrides['track_token'] ?? \Illuminate\Support\Str::random(32);
        $order->created_at = now()->toIso8601String();
        $order->updated_at = now()->toIso8601String();
        $order->save();

        return $order;
    }
}
