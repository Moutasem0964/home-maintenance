<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * orders — aggregate root (ERD: Orders & Work).
     * lat/lng are a historical snapshot of the visit location, NOT a copy of the
     * address (the client may edit the address later). commission_rate/amount are
     * frozen at creation time (SRS note 9) so changing the platform rate never
     * affects old orders. closure_code is generated and verified SERVER-SIDE ONLY
     * (SRS note 4) and stored encrypted; it is never sent to the technician device.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('technician_id')->nullable()->constrained('technicians');
            $table->foreignId('service_category_id')->constrained('service_categories');
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('parent_order_id')->nullable()->constrained('orders'); // warranty / addon origin
            $table->decimal('lat', 10, 7);  // snapshot
            $table->decimal('lng', 10, 7);  // snapshot
            $table->text('description')->nullable();
            $table->string('kind', 20)->default('normal'); // enum OrderKind
            $table->string('type', 20);                    // enum OrderType (urgent|scheduled)
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 30)->index();         // enum OrderStatus
            $table->text('closure_code')->nullable();      // encrypted cast, server-side only
            $table->timestamp('closure_expires_at')->nullable();
            $table->unsignedTinyInteger('closure_attempts')->default(0);
            $table->timestamp('closure_verified_at')->nullable();
            $table->timestamp('dispute_deadline_at')->nullable();
            $table->timestamp('warranty_until')->nullable();
            $table->decimal('commission_rate', 5, 4);      // snapshot
            $table->decimal('commission_amount', 10, 2)->default(0); // snapshot
            $table->decimal('inspection_fee', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
            $table->index(['technician_id', 'status']);
            $table->index('dispute_deadline_at'); // cron: releaseExpiredHolds
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
