<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gönderen hafızası: kalkışı yazmayan liste gönderen numaraların bilinen kalkış yeri (yönetici öğretti ya da geçmişten çıktı). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sender_pickups')) {
            return;
        }
        Schema::create('sender_pickups', function (Blueprint $table): void {
            $table->id();
            $table->char('phone_hash', 64)->unique(); // numaranın SHA-256'sı (düz numara saklanmaz)
            $table->string('pickup_label', 120); // "Kocaeli Gebze"
            $table->unsignedSmallInteger('province_code')->nullable();
            $table->string('district', 80)->nullable();
            $table->string('source', 16)->default('admin'); // admin | history
            $table->unsignedInteger('hits')->default(0); // kaç mesajda kullanıldı
            $table->foreignId('taught_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_pickups');
    }
};
