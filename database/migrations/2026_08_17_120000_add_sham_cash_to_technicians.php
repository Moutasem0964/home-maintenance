<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            // Sham Cash payout account, saved once by the technician. Number is encrypted at
            // rest (financial PII); text because ciphertext is far longer than 16 digits.
            $table->text('sham_cash_number')->nullable()->after('proof_url');
            $table->string('sham_cash_name')->nullable()->after('sham_cash_number');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn(['sham_cash_number', 'sham_cash_name']);
        });
    }
};
