<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat read-tracking for the internal order conversation.
 *
 * The chat is strictly two-party: the customer on one side, staff (any
 * admin/operator) on the other. Instead of a per-user read pivot, each
 * message carries two nullable read timestamps:
 *
 *   staff_read_at     — when the staff side read this message
 *   customer_read_at  — when the owning customer read this message
 *
 * Unread for staff    = is_operator_response = false AND staff_read_at IS NULL
 * Unread for customer = is_operator_response = true  AND customer_read_at IS NULL
 *
 * This matches how the panels actually work (staff share one inbox view,
 * the customer only sees their own threads) without a pivot table.
 * Timestamps follow the codebase-wide ISO-8601 string convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('staff_read_at', 32)->nullable()->after('is_operator_response');
            $table->string('customer_read_at', 32)->nullable()->after('staff_read_at');
            $table->index(['order_id', 'is_operator_response'], 'idx_ticket_order_unread');
        });

        // Upgrade path: messages sent BEFORE read-tracking existed were all
        // delivered (often answered) without receipts. Backfilling both stamps
        // keeps the new «پیام جدید» badges honest — only conversations that
        // get new activity after this deploy light up.
        $now = now()->toIso8601String();
        DB::table('ticket_messages')->whereNull('staff_read_at')->update(['staff_read_at' => $now]);
        DB::table('ticket_messages')->whereNull('customer_read_at')->update(['customer_read_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_order_unread');
            $table->dropColumn(['staff_read_at', 'customer_read_at']);
        });
    }
};
