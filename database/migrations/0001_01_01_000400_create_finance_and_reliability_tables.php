<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('processing')->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
        });
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('load_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 32)->index();
            $table->string('provider', 32)->default('paytr');
            $table->string('merchant_oid')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->decimal('service_fee_amount', 19, 4)->default(0);
            $table->decimal('insurance_amount', 19, 4)->default(0);
            $table->string('status', 32)->default('created')->index();
            $table->json('request_snapshot')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_order_id')->constrained()->restrictOnDelete();
            $table->string('provider_event_id')->nullable()->unique();
            $table->string('event_type', 64)->index();
            $table->string('status', 24)->default('received')->index();
            $table->char('payload_hash', 64);
            $table->longText('payload_encrypted')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['payment_order_id', 'created_at']);
        });
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('account_type', 32)->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('TRY');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('transaction_type', 64)->index();
            $table->string('description');
            $table->timestamp('occurred_at')->index();
            $table->timestamp('posted_at')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 8);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->timestamps();
            $table->index(['ledger_account_id', 'created_at']);
        });
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_recipient_id')->nullable()->index();
            $table->string('iban_last4', 4)->nullable();
            $table->text('encrypted_iban')->nullable();
            $table->string('account_holder');
            $table->boolean('is_verified')->default(false)->index();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('iban', 34)->nullable();
            $table->string('bank_name')->nullable();
            $table->decimal('total_amount', 19, 4);
            $table->decimal('commission_amount', 19, 4)->default(0);
            $table->decimal('net_amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 32)->default('pending')->index();
            $table->string('reference_no')->nullable()->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status', 'available_at']);
        });
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32)->default('paytr');
            $table->string('provider_reference')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempt_no');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->unique(['payout_id', 'attempt_no']);
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('plan_code', 64)->index();
            $table->string('provider', 32)->default('paytr');
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->string('interval', 24)->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('subscription_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('invoice_type', 32)->index();
            $table->string('invoice_no')->nullable()->unique();
            $table->string('provider', 32)->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->decimal('base_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('invoice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->unsignedSmallInteger('attempt_no');
            $table->string('status', 24)->default('pending')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->unique(['invoice_id', 'attempt_no']);
        });
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cargo_owner_claim');
            $table->string('driver_proof_photo_path')->nullable();
            $table->text('driver_defense')->nullable();
            $table->string('status', 40)->default('open')->index();
            $table->text('arbitration_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['load_id', 'status']);
        });
        Schema::create('dispute_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->restrictOnDelete();
            $table->string('beneficiary_type', 32);
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 24)->default('percentage');
            $table->decimal('value', 19, 4);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('aggregate_type', 64)->index();
            $table->string('aggregate_id', 64)->index();
            $table->string('event_type', 128)->index();
            $table->json('payload');
            $table->timestamp('available_at')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'available_at']);
        });
    }

    public function down(): void
    {
        foreach (['outbox_events', 'coupons', 'dispute_allocations', 'disputes', 'invoice_attempts', 'invoices', 'subscription_cycles', 'subscriptions', 'payout_attempts', 'payouts', 'bank_accounts', 'ledger_entries', 'ledger_transactions', 'ledger_accounts', 'payment_events', 'payment_orders', 'idempotency_keys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
