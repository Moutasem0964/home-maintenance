<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders & Work satellites: appointments, dispatch_offers, quotes,
     * quote_parts, evidences, order_events.
     *
     * dispatch_offers = system→technician job offer ("take this job?").
     * quotes          = technician→client price offer. Two different concepts.
     *
     * appointments: one row per booked visit (inspection/repair/followup).
     * UNIQUE(technician_id, starts_at) is the DB-level double-booking guard
     * (SRS note 12). Arrival/completion/no-show are ORDER states — never
     * duplicated here (single source of truth).
     *
     * order_events: append-only audit trail (no updated_at, no deletes) —
     * backbone of the dispute dashboard evidence (FR-A3).
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained();
            $table->string('type', 20)->default('inspection'); // enum AppointmentType
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->index(); // enum AppointmentStatus
            $table->timestamp('reminder_sent_at')->nullable(); // idempotent reminders (UC-26)
            $table->timestamps();

            $table->unique(['technician_id', 'starts_at']); // double-booking guard
            $table->index(['status', 'starts_at']);         // cron: activateDueAppointments
        });

        Schema::create('dispatch_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained();
            $table->string('status', 20)->index(); // enum DispatchOfferStatus
            $table->string('decline_reason')->nullable(); // analytics
            $table->timestamp('offered_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['order_id', 'technician_id']); // never offer same order twice to same tech
            $table->index(['status', 'expires_at']);       // cron: reassign on timeout
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained();
            $table->string('type', 20)->default('initial'); // enum QuoteType (initial|addon)
            $table->decimal('labor_cost', 10, 2);
            $table->unsignedSmallInteger('warranty_days')->default(0);
            $table->string('justification')->nullable(); // mandatory when price > anomaly threshold (FR-A2)
            $table->string('status', 20)->index();       // enum QuoteStatus
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']); // cron: expireStaleQuotes
        });

        Schema::create('quote_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('classification', 20); // enum PartClassification
            $table->string('image_url');          // mandatory — protects both parties
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('type', 20); // enum EvidenceType (before|after|invoice|dispute)
            $table->string('image_url');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users'); // null = system event
            $table->string('event_type', 40)->index(); // enum OrderEventType
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only: no updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('evidences');
        Schema::dropIfExists('quote_parts');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('dispatch_offers');
        Schema::dropIfExists('appointments');
    }
};
