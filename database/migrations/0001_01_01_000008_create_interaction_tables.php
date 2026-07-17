<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disputes, Reviews & Chat domain.
     * Raising a dispute freezes the escrow release (handled in DisputeService
     * inside one DB transaction competing on the order row lock — SRS note 3).
     * notifications stores a polymorphic DOMAIN reference (notifiable_type/id),
     * not a UI route — the app decides which screen to open.
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('raised_by')->constrained('users');
            $table->string('reason', 30);  // enum DisputeReason
            $table->text('description')->nullable();
            $table->string('status', 20)->index(); // enum DisputeStatus
            $table->string('resolution', 30)->nullable(); // enum DisputeResolution
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained(); // one review per order
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('technician_id')->constrained();
            $table->unsignedTinyInteger('cleanliness');
            $table->unsignedTinyInteger('quality');
            $table->unsignedTinyInteger('price_rating');
            $table->text('comment')->nullable();
            $table->boolean('price_anomaly_flag')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained(); // one conversation per order
            $table->string('status', 20); // enum ConversationStatus — read_only after closure
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('message_text')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20); // enum NotificationCategory
            $table->string('title');
            $table->text('body');
            $table->string('notifiable_type', 40)->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('disputes');
    }
};
