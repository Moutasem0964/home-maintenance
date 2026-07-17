<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identity & Profiles domain: technicians, addresses, technician_portfolios.
     * Rule 1 (diagrams doc): every technician-role FK in the schema points to
     * technicians.id, never users.id, so a client id can never be misused.
     */
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index(); // enum TechnicianStatus
            $table->decimal('rating_avg', 3, 2)->default(0);      // derived
            $table->unsignedInteger('rating_count')->default(0);  // derived
            $table->decimal('acceptance_rate', 5, 2)->default(0); // derived
            $table->boolean('is_available')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->text('id_doc_url')->nullable();          // encrypted cast
            $table->text('selfie_url')->nullable();          // encrypted cast
            $table->text('criminal_record_url')->nullable(); // encrypted cast
            $table->text('proof_url')->nullable();           // encrypted cast
            $table->timestamp('charter_accepted_at')->nullable();
            $table->unsignedSmallInteger('daily_order_limit')->nullable();
            $table->timestamps();

            // Spatial pre-filter for nearest-technician (SRS note 13):
            // bounding-box on the composite index, exact distance on the few rows left.
            $table->index(['current_lat', 'current_lng']);
            $table->index(['is_available', 'status']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('building_no', 20)->nullable();
            $table->string('floor', 20)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('technician_portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->string('image_url');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_portfolios');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('technicians');
    }
};
