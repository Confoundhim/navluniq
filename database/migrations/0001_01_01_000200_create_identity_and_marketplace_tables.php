<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cargo_owner_profiles', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 24)->default('individual')->index();
            $table->string('company_title')->nullable(); $table->string('tax_office')->nullable();
            $table->string('tax_no', 10)->nullable()->unique(); $table->string('tc_no', 11)->nullable()->unique();
            $table->boolean('nvi_verified')->default(false); $table->boolean('gib_verified')->default(false);
            $table->string('kyc_status', 24)->default('unsubmitted')->index(); $table->text('kyc_notes')->nullable();
            $table->timestamp('kyc_submitted_at')->nullable(); $table->timestamp('kyc_verified_at')->nullable();
            $table->foreignId('kyc_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('premium_until')->nullable()->index();
            foreach (['avatar_path','driver_license_path','src_document_path','psychotechnic_path','k_document_path','liability_insurance_path','selfie_with_id_path'] as $column) $table->string($column)->nullable();
            $table->json('ocr_data')->nullable(); $table->string('kyc_status', 24)->default('unsubmitted')->index();
            $table->text('kyc_notes')->nullable(); $table->timestamp('kyc_submitted_at')->nullable(); $table->timestamp('kyc_verified_at')->nullable();
            $table->foreignId('kyc_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 64)->index(); $table->string('storage_disk', 64)->default('kyc_private'); $table->string('storage_path');
            $table->char('sha256', 64)->nullable()->index(); $table->string('status', 24)->default('pending')->index(); $table->json('extracted_data')->nullable();
            $table->timestamp('expires_at')->nullable()->index(); $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable(); $table->text('review_notes')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->index(['user_id','document_type','status']);
        });
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('consent_type', 64);
            $table->string('document_version', 64); $table->boolean('granted'); $table->timestamp('recorded_at'); $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address',45)->nullable(); $table->text('user_agent')->nullable(); $table->timestamps();
            $table->index(['user_id','consent_type','recorded_at']);
        });
        Schema::create('driver_vehicles', function (Blueprint $table) {
            $table->id(); $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete(); $table->string('plate',32)->unique();
            $table->string('brand'); $table->string('model'); $table->string('vehicle_type',48)->index();
            $table->string('ruhsat_path')->nullable(); $table->string('vehicle_photo_path')->nullable(); $table->boolean('is_active')->default(true)->index();
            $table->timestamps(); $table->softDeletes(); $table->index(['driver_profile_id','is_active']);
        });
        Schema::create('loads', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('cargo_owner_profile_id')->constrained()->restrictOnDelete(); $table->foreignId('driver_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type',24)->default('internal')->index(); $table->string('visibility',24)->default('private')->index();
            $table->string('pickup_location'); $table->string('delivery_location');
            $table->geometry('pickup_coordinates', subtype:'point', srid:4326)->nullable(); $table->geometry('delivery_coordinates', subtype:'point', srid:4326)->nullable();
            $table->dateTime('pickup_date')->index(); $table->dateTime('delivery_date')->nullable()->index(); $table->string('vehicle_type',48)->index();
            $table->string('goods_type'); $table->unsignedInteger('weight')->nullable(); $table->unsignedInteger('volume')->nullable();
            $table->decimal('price',19,4)->nullable(); $table->char('currency',3)->default('TRY');
            $table->string('escrow_status',32)->default('pending_payment')->index(); $table->string('status',32)->default('active_seeking')->index();
            $table->text('dispute_reason')->nullable(); $table->text('rejection_reason')->nullable(); $table->string('e_irsaliye_no')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index(); $table->timestamp('cancelled_at')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->index(['cargo_owner_profile_id','status','pickup_date']); $table->index(['driver_profile_id','status']);
        });
        Schema::create('offers', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->nullable()->unique(); $table->foreignId('load_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->restrictOnDelete(); $table->decimal('amount',19,4); $table->char('currency',3)->default('TRY');
            $table->text('message')->nullable(); $table->string('status',24)->default('pending')->index(); $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable(); $table->timestamps(); $table->softDeletes(); $table->index(['load_id','status','created_at']);
        });
        Schema::create('shipments', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->nullable()->unique(); $table->foreignId('load_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('accepted_offer_id')->nullable()->constrained('offers')->nullOnDelete(); $table->foreignId('driver_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('driver_vehicles')->nullOnDelete(); $table->string('status',32)->default('awaiting_pickup')->index();
            $table->timestamp('pickup_confirmed_at')->nullable(); $table->timestamp('in_transit_at')->nullable(); $table->timestamp('delivered_at')->nullable();
            $table->timestamp('owner_approved_at')->nullable(); $table->timestamp('owner_rejected_at')->nullable(); $table->timestamp('auto_approval_due_at')->nullable()->index(); $table->timestamps();
        });
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id(); $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete(); $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->geometry('coordinates', subtype:'point', srid:4326); $table->decimal('speed',7,2)->default(0); $table->decimal('heading',6,2)->default(0);
            $table->decimal('accuracy_meters',8,2)->nullable(); $table->timestamp('recorded_at')->useCurrent()->index(); $table->spatialIndex('coordinates');
            $table->index(['driver_profile_id','recorded_at']);
        });
        Schema::create('shipment_evidence', function (Blueprint $table) {
            $table->id(); $table->foreignId('shipment_id')->constrained()->restrictOnDelete(); $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('type',32)->index(); $table->string('storage_disk',64)->default('private'); $table->string('storage_path'); $table->char('sha256',64)->nullable();
            $table->json('metadata')->nullable(); $table->timestamp('captured_at')->nullable(); $table->timestamps(); $table->index(['shipment_id','type']);
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id(); $table->foreignId('load_id')->unique()->constrained()->restrictOnDelete(); $table->foreignId('shipment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('opened_at')->nullable(); $table->timestamp('closed_at')->nullable(); $table->timestamps();
        });
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id(); $table->foreignId('conversation_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('last_read_at')->nullable(); $table->timestamps(); $table->unique(['conversation_id','user_id']);
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id(); $table->foreignId('conversation_id')->constrained()->cascadeOnDelete(); $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body'); $table->json('attachments')->nullable(); $table->timestamp('sent_at')->useCurrent()->index(); $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable(); $table->index(['conversation_id','sent_at']);
        });
    }
    public function down(): void {
        foreach (['messages','conversation_participants','conversations','shipment_evidence','driver_locations','shipments','offers','loads','driver_vehicles','user_consents','kyc_documents','driver_profiles','cargo_owner_profiles'] as $table) Schema::dropIfExists($table);
    }
};
