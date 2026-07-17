<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Service Catalog domain: service_categories (self-referencing tree), technician_services. */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('name');
            $table->string('icon_url')->nullable();
            $table->decimal('guide_price', 10, 2)->nullable(); // anomaly threshold base (FR-A2)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('technician_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['technician_id', 'service_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_services');
        Schema::dropIfExists('service_categories');
    }
};
