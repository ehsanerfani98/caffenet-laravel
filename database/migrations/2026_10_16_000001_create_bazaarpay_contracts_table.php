<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BazaarPay Direct Pay contracts — one row per `contract_token` issued by
 * /direct-pay/contract/init. The cached state is refreshed from the gateway
 * trace endpoint; rows double as signing history for support audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bazaarpay_contracts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('contract_token')->unique();
            // wallet (Bazaar wallet) | direct_debit (bank)
            $table->string('type')->default('wallet');
            // daily | weekly | monthly | quarterly | semiannual | yearly
            $table->string('period')->default('monthly');
            $table->integer('amount_limit');           // per-period cap, Toman
            // new | active | declined | cancelled | expired
            $table->string('state')->default('new');
            $table->string('expiration_time', 40)->nullable();
            $table->string('limit_expiration_time', 40)->nullable();
            $table->integer('limit_remaining_amount')->nullable();
            $table->string('created_at', 32);
            $table->string('updated_at', 32)->nullable();
            $table->string('activated_at', 32)->nullable();
            $table->index('user_id', 'idx_bpc_user');
            $table->index('state', 'idx_bpc_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bazaarpay_contracts');
    }
};
