<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client-supplied idempotency token for order creation. A retried "create order"
     * request (network retry, double tap, at-least-once job) reuses the same token,
     * so we return the existing order instead of creating a second one and holding
     * the inspection fee twice. Unique per client; NULLs are allowed (multiple legacy
     * rows without a key coexist).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('client_id');
            $table->unique(['client_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
