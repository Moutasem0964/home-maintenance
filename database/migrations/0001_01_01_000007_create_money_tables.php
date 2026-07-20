<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money & Ledger domain (SRS notes 1, 3, 5).
     *
     * wallet_transactions is THE source of truth: an append-only double-entry
     * ledger (every operation writes entries summing to zero). Balances on
     * wallets are derived caches updated inside the same DB transaction.
     * Corrections are made with reversal entries — rows are never updated
     * or deleted. payments.idempotency_key and wallet_transactions.reference
     * are the duplicate-operation guards (SRS note 5).
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('available_balance', 12, 2)->default(0); // derived
            $table->decimal('held_balance', 12, 2)->default(0);      // derived
            $table->char('currency', 3)->default('SYP');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payer_id')->constrained('users');
            // payee stays polymorphic on users on purpose: it may be the platform (rule 1 exception)
            $table->foreignId('payee_id')->nullable()->constrained('users');
            $table->string('type', 20);   // enum PaymentType (inspection|repair|addon)
            $table->decimal('amount', 12, 2);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->string('status', 30)->index(); // enum PaymentStatus
            $table->string('idempotency_key', 64)->unique(); // duplicate-hold guard
            $table->timestamp('held_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('type', 20);         // enum TxnType
            $table->string('balance_type', 20); // enum BalanceType (available|held)
            $table->decimal('amount', 12, 2);   // signed
            $table->string('reference', 255)->unique(); // duplicate-entry guard; fits escrow ref + UUID/ULID operationId
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only: no updated_at, never deleted

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('gateway_reference', 100)->unique();
            $table->string('status', 20); // enum TopUpStatus
            $table->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20); // enum WithdrawalMethod
            $table->text('destination_details')->nullable(); // encrypted cast
            $table->string('status', 20)->index(); // enum WithdrawalStatus
            $table->string('receipt_url')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users'); // admin, not a technician
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('discount_type', 20); // enum DiscountType
            $table->decimal('discount_value', 10, 2);
            $table->string('applies_to', 20); // enum PromoAppliesTo
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0); // derived, bump inside same txn
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promo_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->timestamp('created_at')->nullable();

            $table->unique(['promo_code_id', 'user_id']); // one use per person
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->string('value');
            $table->string('data_type', 10); // enum SettingDataType
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('top_ups');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wallets');
    }
};
