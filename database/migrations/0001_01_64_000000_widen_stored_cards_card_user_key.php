<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kayıtlı kartın kuruluş kullanıcı anahtarı (card_user_key) da şifreli saklanır (L2); şifreli değer 190 karaktere sığmaz,
 * kolon text olur. Eski düz metin satırlar olduğu gibi okunur (model toleranslı), ilk kayıtta şifrelenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stored_cards') && Schema::hasColumn('stored_cards', 'card_user_key')) {
            Schema::table('stored_cards', function (Blueprint $table): void {
                $table->text('card_user_key')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stored_cards') && Schema::hasColumn('stored_cards', 'card_user_key')) {
            Schema::table('stored_cards', function (Blueprint $table): void {
                $table->string('card_user_key', 190)->change();
            });
        }
    }
};
