<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer's preferred contact channels to an order.
 *
 * Stored as a JSON array of channel keys (see Order::CONTACT_METHODS) so a
 * customer can pick several ways to be reached (in-app chat, phone, SMS,
 * Telegram, WhatsApp, Rubika, Bale, Eitaa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'contact_methods_json')) {
                $table->text('contact_methods_json')->nullable()->after('customer_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'contact_methods_json')) {
                $table->dropColumn('contact_methods_json');
            }
        });
    }
};
