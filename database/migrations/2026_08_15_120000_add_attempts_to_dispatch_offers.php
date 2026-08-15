<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many times this order has been offered to this technician. The unique
     * (order_id, technician_id) index means we reuse the same row on a re-offer
     * to a timed-out technician; this counter caps those retries.
     */
    public function up(): void
    {
        Schema::table('dispatch_offers', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_offers', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
