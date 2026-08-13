<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting-for-parts pause: the technician can pause an in-progress order while sourcing
 * a part. parts_waiting_until is the deadline; parts_overdue_flagged_at makes the overdue
 * sweep idempotent (flag the admin once per waiting episode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('parts_waiting_until')->nullable()->after('arrived_at');
            $table->timestamp('parts_overdue_flagged_at')->nullable()->after('parts_waiting_until');
            $table->string('parts_note')->nullable()->after('parts_overdue_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['parts_waiting_until', 'parts_overdue_flagged_at', 'parts_note']);
        });
    }
};
