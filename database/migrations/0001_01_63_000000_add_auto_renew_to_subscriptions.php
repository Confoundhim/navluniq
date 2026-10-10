<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik yenilenen premium abonelik (Osman, 2026-10-09: "bir kere kayıt olsun, her ay otomatik devam etsin").
 * Kart NavlunIQ'da değil ödeme kuruluşunda saklanır; burada yalnız kuruluşun verdiği kart anahtarı (token), son 4 hane ve
 * kart ailesi durur. Abonelik satırı yenileme tercihini, bağlı kartı ve deneme sayacını taşır; ödeme emri satın alma
 * ekranındaki tercihi ve (yenileme çekiminde) kullanılan kartı taşır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stored_cards')) {
            Schema::create('stored_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 32);
                $table->string('card_user_key', 190);
                $table->text('card_token'); // şifreli saklanır (model cast)
                $table->string('last_four', 4)->nullable();
                $table->string('card_association', 32)->nullable(); // VISA, MASTER_CARD, TROY…
                $table->string('card_family', 64)->nullable(); // Bonus, Axess…
                $table->string('bank_name', 120)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'provider']);
            });
        }
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->boolean('auto_renew')->default(false)->after('interval');
            }
            if (! Schema::hasColumn('subscriptions', 'renew_months')) {
                $table->unsignedTinyInteger('renew_months')->default(1)->after('auto_renew');
            }
            if (! Schema::hasColumn('subscriptions', 'stored_card_id')) {
                $table->foreignId('stored_card_id')->nullable()->after('renew_months')->constrained('stored_cards')->nullOnDelete();
            }
            if (! Schema::hasColumn('subscriptions', 'renewal_failures')) {
                $table->unsignedTinyInteger('renewal_failures')->default(0)->after('stored_card_id');
            }
            if (! Schema::hasColumn('subscriptions', 'last_renewal_error')) {
                $table->string('last_renewal_error', 255)->nullable()->after('renewal_failures');
            }
            if (! Schema::hasColumn('subscriptions', 'next_renewal_attempt_at')) {
                $table->timestamp('next_renewal_attempt_at')->nullable()->after('last_renewal_error');
            }
        });
        Schema::table('payment_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_orders', 'auto_renew')) {
                $table->boolean('auto_renew')->default(false)->after('subscription_months');
            }
            if (! Schema::hasColumn('payment_orders', 'stored_card_id')) {
                $table->foreignId('stored_card_id')->nullable()->after('auto_renew')->constrained('stored_cards')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_orders', 'stored_card_id')) {
                $table->dropConstrainedForeignId('stored_card_id');
            }
            if (Schema::hasColumn('payment_orders', 'auto_renew')) {
                $table->dropColumn('auto_renew');
            }
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (Schema::hasColumn('subscriptions', 'stored_card_id')) {
                $table->dropConstrainedForeignId('stored_card_id');
            }
            foreach (['auto_renew', 'renew_months', 'renewal_failures', 'last_renewal_error', 'next_renewal_attempt_at'] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('stored_cards');
    }
};
