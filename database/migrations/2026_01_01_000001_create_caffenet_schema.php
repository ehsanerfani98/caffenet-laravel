<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline schema of Caffenet Lite — a faithful port of the original
 * migrations 001..005 of the Node/SQLite implementation so that existing
 * databases can be adopted (or fresh ones created) without any drift:
 *
 *  001_create_initial_schema        → all tables + base indexes
 *  002_add_users_permissions_json   → users.permissions_json
 *  003_add_orders_coupon_id         → orders.coupon_id
 *  005_add_order_payment_status     → orders(status) index (statuses are created
 *                                     as pending_payment from day one)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('phone')->unique();
            $table->string('name');
            $table->string('role')->default('customer'); // customer | operator | admin
            $table->integer('wallet_balance')->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->text('permissions_json')->nullable();
            $table->string('created_at', 32);
            $table->index('phone', 'idx_users_phone');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('icon_name');
            $table->text('description')->nullable();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('category_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('base_price')->default(0);
            $table->string('estimated_time')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('fields_json');
            $table->text('pricing_formula')->nullable();
            $table->index('category_id', 'idx_services_category');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_number')->unique();
            $table->string('service_id');
            $table->string('service_title');
            $table->string('customer_id');
            $table->string('customer_phone');
            $table->string('customer_name');
            // Payment-centric lifecycle: an order is created as
            // pending_payment and only becomes submitted after a verified
            // payment (online or wallet). pending_payment/failed never enter
            // the operator queue.
            $table->string('status')->default('pending_payment');
            $table->string('urgency')->default('normal');
            $table->string('delivery_method')->default('download');
            $table->text('delivery_address')->nullable();
            $table->string('delivery_postal_code', 16)->nullable();
            $table->text('form_data_json');
            $table->text('customer_files_json');
            $table->text('result_files_json');
            $table->integer('total_price')->default(0);
            $table->integer('discount_amount')->default(0);
            $table->integer('paid_amount')->default(0);
            $table->string('payment_method')->default('online');
            $table->string('payment_tracking_code')->default('');
            $table->string('assigned_operator_id')->nullable();
            $table->string('assigned_operator_name')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('coupon_id')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('review_comment')->nullable();
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->index('customer_id', 'idx_orders_customer');
            $table->index('status', 'idx_orders_status');
            $table->index('created_at', 'idx_orders_created');
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('action');
            $table->string('performed_by');
            $table->text('note')->nullable();
            $table->string('timestamp', 32);
            $table->index('order_id', 'idx_order_logs_order');
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('sender_role');
            $table->string('sender_name');
            $table->text('message');
            $table->boolean('is_operator_response')->default(false);
            $table->string('timestamp', 32);
            $table->index('order_id', 'idx_ticket_order');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code')->unique();
            $table->unsignedTinyInteger('percent');
            $table->integer('max_amount')->default(0);
            $table->integer('min_order_amount')->default(0);
            $table->string('expire_date', 32);
            $table->integer('usage_count')->default(0);
            $table->integer('max_usage')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('phone');
            $table->text('text');
            $table->string('type')->default('otp'); // otp | order_status | system
            $table->string('status')->default('sent'); // sent | failed | test
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('timestamp', 32);
            $table->index('phone', 'idx_sms_phone');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('phone');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('consumed')->default(false);
            $table->string('request_ip', 64)->nullable();
            $table->string('expires_at', 32);
            $table->string('created_at', 32);
            $table->index('phone', 'idx_otp_phone');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id')->nullable();
            $table->string('user_id');
            $table->string('purpose')->default('order'); // order | wallet
            $table->integer('amount');
            $table->string('authority')->nullable()->unique();
            $table->string('ref_id')->nullable();
            $table->string('card_pan')->nullable();
            $table->string('gateway')->default('zarinpal'); // zarinpal | wallet | test
            $table->string('status')->default('pending'); // pending | paid | failed
            $table->string('created_at', 32);
            $table->string('verified_at', 32)->nullable();
            $table->index('order_id', 'idx_payments_order');
            $table->index('user_id', 'idx_payments_user');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('slider_images', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('image_url');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_target')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('created_at', 32);
        });

        // SQLite pragmas: WAL journal + foreign keys + busy timeout, mirroring
        // the original node:sqlite connection settings.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA journal_mode = WAL');
            DB::statement('PRAGMA foreign_keys = ON');
            DB::statement('PRAGMA busy_timeout = 5000');
        }
    }

    public function down(): void
    {
        foreach (['users', 'categories', 'services', 'orders', 'order_logs', 'ticket_messages',
                  'coupons', 'sms_logs', 'otp_codes', 'payments', 'settings', 'slider_images'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
