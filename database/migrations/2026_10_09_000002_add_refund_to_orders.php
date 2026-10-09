<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund bookkeeping on orders: how much has been refunded to the customer's
 * wallet and when. The order's `status` is moved to `refunded` alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'refund_amount')) {
                $table->integer('refund_amount')->default(0)->after('paid_amount');
            }
            if (! Schema::hasColumn('orders', 'refunded_at')) {
                $table->string('refunded_at', 32)->nullable()->after('refund_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'refunded_at')) {
                $table->dropColumn('refunded_at');
            }
            if (Schema::hasColumn('orders', 'refund_amount')) {
                $table->dropColumn('refund_amount');
            }
        });
    }
};
