<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('bazaar-iap.table', 'bazaar_iap_transactions');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->string('id')->primary();

            // The opaque visit id embedded in the deep-link redirect URL. It is
            // how the callback correlates a purchase back to its origin.
            $blueprint->string('visit_id')->unique();

            $blueprint->string('package_name')->nullable();
            $blueprint->string('sku')->nullable();
            $blueprint->string('purchase_token')->nullable()->index();

            // pending | login | consumed | failed
            $blueprint->string('status')->default('pending')->index();

            // Free-form host tags (order | wallet | subscription | …).
            $blueprint->string('purpose')->nullable();
            $blueprint->string('reference')->nullable()->index();

            $blueprint->integer('amount')->default(0);
            $blueprint->string('message')->nullable();
            $blueprint->json('meta')->nullable();
            $blueprint->timestamp('consumed_at')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('bazaar-iap.table', 'bazaar_iap_transactions'));
    }
};
