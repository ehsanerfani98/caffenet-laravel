<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a user must purge every related row (orders + their logs/tickets/
 * payments, wallet payments, wallet ledger, OTP/SMS artefacts) while keeping
 * the customers' orders an operator merely handled (unassigned).
 */
class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::create([
            'phone' => '09120000001',
            'name' => 'مدیر',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function user(string $role, string $phone): User
    {
        return User::create([
            'phone' => $phone,
            'name' => 'کاربر '.$role,
            'role' => $role,
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function test_deleting_a_user_purges_all_related_rows(): void
    {
        $admin = $this->admin();
        $customer = $this->user('customer', '09120000002');
        $now = now()->toIso8601String();

        $orderId = 'ord-del-1';
        DB::table('orders')->insert([
            'id' => $orderId, 'order_number' => 'CN-0001-0001', 'service_id' => 'srv-x',
            'service_title' => 'خدمت', 'customer_id' => $customer->id,
            'customer_phone' => $customer->phone, 'customer_name' => $customer->name,
            'status' => 'submitted', 'form_data_json' => '{}', 'customer_files_json' => '[]',
            'result_files_json' => '[]', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_logs')->insert([
            'id' => 'log-1', 'order_id' => $orderId, 'action' => 'ثبت', 'performed_by' => 'x', 'timestamp' => $now,
        ]);
        DB::table('ticket_messages')->insert([
            'id' => 'tkt-1', 'order_id' => $orderId, 'sender_role' => 'customer',
            'sender_name' => 'x', 'message' => 'سلام', 'timestamp' => $now,
        ]);
        DB::table('payments')->insert([
            ['id' => 'pay-1', 'order_id' => $orderId, 'user_id' => $customer->id, 'purpose' => 'order', 'amount' => 1000, 'gateway' => 'wallet', 'status' => 'paid', 'created_at' => $now],
            ['id' => 'pay-2', 'order_id' => null, 'user_id' => $customer->id, 'purpose' => 'wallet', 'amount' => 2000, 'gateway' => 'test', 'status' => 'paid', 'created_at' => $now],
        ]);
        DB::table('wallet_transactions')->insert([
            'id' => 'wtx-1', 'user_id' => $customer->id, 'type' => 'topup', 'direction' => 'credit',
            'amount' => 2000, 'balance_after' => 2000, 'created_at' => $now,
        ]);
        DB::table('otp_codes')->insert([
            'id' => 'otp-1', 'phone' => $customer->phone, 'code_hash' => 'h', 'expires_at' => $now, 'created_at' => $now,
        ]);
        DB::table('sms_logs')->insert([
            'id' => 'sms-1', 'phone' => $customer->phone, 'text' => 'کد', 'timestamp' => $now,
        ]);

        $this->actingAs($admin)->deleteJson('/api/users/'.$customer->id)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
        $this->assertDatabaseMissing('order_logs', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('ticket_messages', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('payments', ['id' => 'pay-1']);
        $this->assertDatabaseMissing('payments', ['id' => 'pay-2']);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('otp_codes', ['phone' => $customer->phone]);
        $this->assertDatabaseMissing('sms_logs', ['phone' => $customer->phone]);
    }

    public function test_deleting_an_operator_keeps_but_unassigns_their_orders(): void
    {
        $admin = $this->admin();
        $operator = $this->user('operator', '09120000003');
        $customer = $this->user('customer', '09120000004');
        $now = now()->toIso8601String();

        $orderId = 'ord-keep-1';
        DB::table('orders')->insert([
            'id' => $orderId, 'order_number' => 'CN-0001-0002', 'service_id' => 'srv-x',
            'service_title' => 'خدمت', 'customer_id' => $customer->id,
            'customer_phone' => $customer->phone, 'customer_name' => $customer->name,
            'status' => 'processing', 'form_data_json' => '{}', 'customer_files_json' => '[]',
            'result_files_json' => '[]', 'assigned_operator_id' => $operator->id,
            'assigned_operator_name' => $operator->name, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->actingAs($admin)->deleteJson('/api/users/'.$operator->id)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $operator->id]);
        $this->assertDatabaseHas('orders', ['id' => $orderId]);

        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNull($order->assigned_operator_id);
        $this->assertNull($order->assigned_operator_name);
    }

    public function test_user_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson('/api/users/'.$admin->id)->assertStatus(400);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
