<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual (receipt-backed) top-ups: the client uploads proof of a cash/bank transfer and an
 * admin verifies it before the wallet is credited. Mirrors the withdrawals table's
 * receipt_url + processed_by so both money-in and money-out share the same review shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('top_ups', function (Blueprint $table) {
            $table->string('receipt_url')->nullable()->after('gateway_reference');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('receipt_url');
        });
    }
};
