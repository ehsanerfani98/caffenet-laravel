<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet ledger — one immutable row per money movement on a user's wallet.
 *
 * Every top-up, service purchase (wallet debit), refund, and manual admin
 * charge/deduction is recorded here with a running `balance_after` snapshot so
 * the full history is traceable and reconcilable in the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id');                 // wallet owner
            $table->string('order_id')->nullable();    // linked order (purchase / refund)
            $table->string('payment_id')->nullable();  // linked gateway payment (top-up)
            // topup | purchase | refund | manual_credit | manual_debit
            $table->string('type');
            // credit | debit
            $table->string('direction');
            $table->integer('amount');                 // always positive magnitude
            $table->integer('balance_after');          // snapshot for reconciliation
            $table->string('reference')->nullable();   // tracking code / ref id / token
            $table->text('note')->nullable();          // reason, human readable
            $table->string('performed_by_id')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->text('metadata_json')->nullable();
            $table->string('created_at', 32);
            $table->index('user_id', 'idx_wtx_user');
            $table->index('order_id', 'idx_wtx_order');
            $table->index('type', 'idx_wtx_type');
            $table->index('created_at', 'idx_wtx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
