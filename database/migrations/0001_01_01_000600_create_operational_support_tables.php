<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('cms_contents')->whereIn('key', ['paytr_merchant_key','paytr_merchant_salt','netgsm_pass'])->delete();

        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title'); $table->string('contact_person'); $table->string('contact_phone', 32);
            $table->string('city', 96); $table->string('district', 96); $table->text('address_detail');
            $table->string('type', 16)->default('both')->index(); $table->boolean('is_default')->default(false);
            $table->timestamps(); $table->softDeletes(); $table->index(['user_id', 'type']);
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id(); $table->foreignId('load_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewee_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating'); $table->text('comment')->nullable(); $table->timestamps();
            $table->unique(['load_id','reviewer_id','reviewee_id']); $table->index(['reviewee_id','rating']);
        });
        Schema::create('insurance_quotes', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->nullable()->unique(); $table->foreignId('load_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->string('provider',64); $table->string('provider_quote_id')->nullable()->index();
            $table->decimal('insured_value',19,4); $table->decimal('premium_amount',19,4); $table->char('currency',3)->default('TRY');
            $table->string('status',24)->default('pending')->index(); $table->json('coverage')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->id(); $table->foreignId('insurance_quote_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->restrictOnDelete(); $table->string('provider_policy_id')->nullable()->unique();
            $table->string('policy_number')->nullable()->unique(); $table->string('status',24)->default('pending')->index();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable(); $table->string('document_disk',64)->nullable();
            $table->string('document_path')->nullable(); $table->timestamps();
        });
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id(); $table->foreignId('insurance_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->restrictOnDelete(); $table->string('provider_claim_id')->nullable()->unique();
            $table->string('status',24)->default('open')->index(); $table->decimal('claimed_amount',19,4);
            $table->decimal('approved_amount',19,4)->nullable(); $table->text('description'); $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable(); $table->timestamps();
        });
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id(); $table->foreignId('coupon_id')->constrained()->restrictOnDelete(); $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->restrictOnDelete(); $table->decimal('discount_amount',19,4);
            $table->timestamp('redeemed_at'); $table->timestamps(); $table->unique(['coupon_id','user_id','payment_order_id']);
        });
    }
    public function down(): void
    {
        foreach (['coupon_redemptions','insurance_claims','insurance_policies','insurance_quotes','reviews','saved_addresses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
