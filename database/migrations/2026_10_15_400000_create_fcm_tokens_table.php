<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores Firebase Cloud Messaging device tokens per user.
 *
 * A user may have multiple tokens (one per browser/device). When the
 * server wants to send a push notification, it queries this table by
 * user_id and sends to all active tokens for that user.
 *
 * Tokens are unique — registering the same token twice (e.g. same
 * browser revisiting the site) upserts instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->text('token');
            $table->string('user_agent', 500)->nullable();
            $table->string('platform', 32)->nullable(); // web | android | ios
            $table->boolean('is_active')->default(true);
            $table->string('created_at', 32);
            $table->string('updated_at', 32)->nullable();
            $table->index('user_id', 'idx_fcm_user');
            $table->index('is_active', 'idx_fcm_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
