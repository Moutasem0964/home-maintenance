<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freshness stamp for the dispatch location snapshot. The technician app pings its position
 * periodically; dispatch only considers a technician whose last fix is recent, so a stale
 * "available" position (set at home, then driven away from) can't misroute urgent orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->timestamp('location_updated_at')->nullable()->after('current_lng');
            $table->index('location_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropIndex(['location_updated_at']);
            $table->dropColumn('location_updated_at');
        });
    }
};
