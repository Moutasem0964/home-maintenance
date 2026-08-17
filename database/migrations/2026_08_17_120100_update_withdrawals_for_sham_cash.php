<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            // Sham Cash is the only payout rail now, so a per-request method is meaningless.
            $table->dropColumn('method');
            // Snapshot of the account holder's name at request time (Sham Cash needs name + number).
            $table->string('destination_name')->nullable()->after('destination_details');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('destination_name');
            $table->string('method', 20)->default('sham_cash')->after('amount');
        });
    }
};
