<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * technician_flags — reliability offenses (no-show / withdraw-after-accept) queued
     * for admin assessment. One row per offense, captured at the moment it happens (so
     * the technician is attributed even though a withdraw nulls orders.technician_id).
     * The system only FLAGS; sanctions (suspend/ban) are an admin decision.
     */
    public function up(): void
    {
        Schema::create('technician_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // context
            $table->string('reason', 30);              // enum TechnicianFlagReason
            $table->string('status', 20)->index();     // enum TechnicianFlagStatus (open|reviewed)
            $table->string('outcome', 20)->nullable(); // enum TechnicianFlagOutcome — set on review (dismissed|suspended|banned)
            $table->text('note')->nullable();          // admin's free-text assessment note
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'status']); // "open flags for this tech" + accumulation count
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_flags');
    }
};
