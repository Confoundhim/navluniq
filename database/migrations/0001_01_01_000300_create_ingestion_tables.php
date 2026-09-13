<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('scrapers', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('type',32)->index(); $table->string('source_identifier');
            $table->boolean('is_active')->default(false)->index(); $table->timestamp('last_scraped_at')->nullable();
            $table->timestamp('last_success_at')->nullable(); $table->timestamp('last_failure_at')->nullable(); $table->text('last_error')->nullable();
            $table->timestamps(); $table->softDeletes(); $table->unique(['type','source_identifier']);
        });
        Schema::create('source_permissions', function (Blueprint $table) {
            $table->id(); $table->foreignId('scraper_id')->unique()->constrained()->cascadeOnDelete(); $table->string('permission_basis',64);
            $table->string('visibility',24)->default('private')->index(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable(); $table->timestamp('expires_at')->nullable()->index(); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('scraped_loads', function (Blueprint $table) {
            $table->id(); $table->foreignId('scraper_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_permission_id')->nullable()->constrained()->nullOnDelete(); $table->char('content_hash',64)->nullable()->unique();
            $table->text('raw_message'); $table->string('sender_phone')->nullable(); $table->text('encrypted_sender_phone')->nullable();
            $table->string('pickup_location')->nullable(); $table->string('delivery_location')->nullable(); $table->string('goods_type')->nullable();
            $table->unsignedInteger('weight')->nullable(); $table->decimal('price',19,4)->nullable(); $table->char('currency',3)->default('TRY');
            $table->string('status',32)->default('raw_unprocessed')->index(); $table->string('parsed_by_llm')->nullable();
            $table->decimal('parse_confidence',5,4)->nullable(); $table->string('visibility',24)->default('private')->index();
            $table->timestamp('available_to_free_at')->nullable()->index(); $table->timestamp('retention_expires_at')->nullable()->index();
            $table->json('parse_metadata')->nullable(); $table->timestamps(); $table->softDeletes(); $table->index(['scraper_id','status','created_at']);
        });
        Schema::create('ai_provider_usage', function (Blueprint $table) {
            $table->id(); $table->string('provider',64); $table->date('usage_date'); $table->unsignedInteger('request_count')->default(0);
            $table->unsignedBigInteger('input_units')->default(0); $table->unsignedBigInteger('output_units')->default(0);
            $table->unsignedInteger('failure_count')->default(0); $table->boolean('quota_exhausted')->default(false)->index();
            $table->timestamp('quota_resets_at')->nullable(); $table->decimal('estimated_cost',19,6)->default(0); $table->char('currency',3)->default('USD');
            $table->timestamps(); $table->unique(['provider','usage_date']);
        });
        Schema::create('ai_parse_attempts', function (Blueprint $table) {
            $table->id(); $table->foreignId('scraped_load_id')->constrained()->cascadeOnDelete(); $table->string('provider',64)->index();
            $table->string('model',128)->nullable(); $table->string('status',24)->index(); $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code')->nullable(); $table->text('error_message')->nullable(); $table->json('response_metadata')->nullable(); $table->timestamps();
        });
    }
    public function down(): void {
        foreach (['ai_parse_attempts','ai_provider_usage','scraped_loads','source_permissions','scrapers'] as $table) Schema::dropIfExists($table);
    }
};
