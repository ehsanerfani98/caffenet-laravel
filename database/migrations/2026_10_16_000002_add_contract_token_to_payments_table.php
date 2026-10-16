<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Direct Pay linkage to payments: for BazaarPay order payments the
 * pending row is created BEFORE the user signs the contract, keyed by
 * contract_token, and the checkout authority is filled in later when the
 * payment executes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('contract_token')->nullable()->index('idx_payments_contract');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_contract');
            $table->dropColumn('contract_token');
        });
    }
};
