<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ticari elektronik ileti (pazarlama e-postası) onayı: ETK/İYS gereği ayrı, işaretlenmemiş onay; geri alınabilir. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'marketing_consent_at')) {
                $table->timestamp('marketing_consent_at')->nullable()->index()->after('phone_verified_at');
            }
            if (! Schema::hasColumn('users', 'marketing_consent_revoked_at')) {
                $table->timestamp('marketing_consent_revoked_at')->nullable()->after('marketing_consent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['marketing_consent_at', 'marketing_consent_revoked_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
