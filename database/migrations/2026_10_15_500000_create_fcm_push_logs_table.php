<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores every FCM push notification the server attempts to send —
 * successful or failed. Powers the "تاریخچه پوش‌های ارسالی" panel in
 * the admin dashboard so the admin can audit:
 *  - which user got which notification
 *  - when it was sent
 *  - whether FCM accepted it (status=sent) or rejected it (status=failed)
 *  - the title/body that was sent
 *  - the channel: 'user' (direct to user tokens) | 'topic' (broadcast)
 *
 * `target` holds either the user_id (for channel=user) or the topic name
 * (for channel=topic) so the audit trail is self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_push_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('channel')->default('user'); // user | topic
            $table->string('target')->nullable();       // user_id or topic name
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('data_json')->nullable();      // JSON snapshot of data payload
            $table->string('status')->default('pending'); // pending | sent | failed | partial
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error')->nullable();
            $table->string('sent_by')->nullable();      // user id of the admin (for manual sends)
            $table->string('created_at', 32);
            $table->index(['channel', 'created_at'], 'idx_fcmlog_channel');
            $table->index('target', 'idx_fcmlog_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_push_logs');
    }
};
