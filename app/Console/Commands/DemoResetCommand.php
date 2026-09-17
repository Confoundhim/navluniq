<?php

namespace App\Console\Commands;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Deneme hesaplarını sıfırlar: verilen e-posta/telefonlara ait kullanıcıları tüm bağlı
 * verileriyle siler, ardından hem şoför hem yük sahibi rolüne sahip, belgeleri onaylı
 * tek bir deneme hesabı oluşturur. Yalnız --force ile yazma yapar.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--email=* : Silinecek hesapların e-postaları}
        {--phone=* : Silinecek hesapların telefonları (05xx...)}
        {--demo-email=serce8378@gmail.com : Oluşturulacak deneme hesabının e-postası}
        {--demo-phone=05376429671 : Deneme hesabının telefonu}
        {--demo-name=Mehmet Demo : Ad Soyad}
        {--demo-password=Password123! : Şifre}
        {--force : Gerçekten sil ve oluştur}';

    protected $description = 'Deneme hesaplarını ve verilerini siler; hem şoför hem yük sahibi rollü onaylı deneme hesabı oluşturur';

    public function handle(): int
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $this->option('email')))));
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => Phone::normalize((string) $p), (array) $this->option('phone')))));
        $demoEmail = mb_strtolower(trim((string) $this->option('demo-email')));
        $demoPhone = Phone::normalize((string) $this->option('demo-phone'));
        if (! $demoPhone) {
            $this->error('Deneme telefonu geçersiz.');

            return self::FAILURE;
        }
        // Deneme hesabının kendi e-posta/telefonu da temizlenecekler arasında olmalı (yeniden oluşturulur).
        $emails[] = $demoEmail;
        $phones[] = $demoPhone;

        $users = User::query()->withTrashed()
            ->where(fn ($q) => $q->whereIn('email', $emails)->orWhereIn('phone', $phones))
            ->get();

        $this->info('Silinecek hesaplar: '.($users->isEmpty() ? 'yok' : ''));
        foreach ($users as $u) {
            $this->line(sprintf('  #%d %s %s · %s · %s · rol: %s', $u->id, $u->first_name, $u->last_name, $u->email, $u->phone, $u->current_role));
        }

        if (! $this->option('force')) {
            $this->warn('Yazma yapılmadı. Silmek ve deneme hesabını oluşturmak için --force ekleyin.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($users, $demoEmail, $demoPhone): void {
            $this->purge($users->pluck('id')->all());
            $this->createDemo($demoEmail, $demoPhone);
        });

        $this->info('Tamamlandı.');
        $this->line("  Giriş: {$demoEmail} / ".$this->option('demo-password')."  (kod e-postaya gelir)");
        $this->line('  Roller: yük sahibi + şoför (panelde sol alttaki rol değiştirici ile geçilir)');

        return self::SUCCESS;
    }

    /** @param list<int> $userIds */
    private function purge(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $driverIds = DB::table('driver_profiles')->whereIn('user_id', $userIds)->pluck('id')->all();
        $ownerIds = DB::table('cargo_owner_profiles')->whereIn('user_id', $userIds)->pluck('id')->all();
        $loadIds = DB::table('loads')->where(fn ($q) => $q->whereIn('cargo_owner_profile_id', $ownerIds ?: [0])->orWhereIn('driver_profile_id', $driverIds ?: [0]))->pluck('id')->all();
        $offerIds = DB::table('offers')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('driver_profile_id', $driverIds ?: [0]))->pluck('id')->all();
        $shipmentIds = DB::table('shipments')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('driver_profile_id', $driverIds ?: [0])->orWhereIn('accepted_offer_id', $offerIds ?: [0]))->pluck('id')->all();
        $orderIds = DB::table('payment_orders')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('user_id', $userIds))->pluck('id')->all();
        $payoutIds = DB::table('payouts')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('user_id', $userIds))->pluck('id')->all();
        $disputeIds = DB::table('disputes')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('opened_by', $userIds))->pluck('id')->all();
        $ledgerAccountIds = Schema::hasTable('ledger_accounts') ? DB::table('ledger_accounts')->whereIn('user_id', $userIds)->pluck('id')->all() : [];
        $quoteIds = Schema::hasTable('insurance_quotes') ? DB::table('insurance_quotes')->where(fn ($q) => $q->whereIn('load_id', $loadIds ?: [0])->orWhereIn('user_id', $userIds))->pluck('id')->all() : [];
        $policyIds = $quoteIds && Schema::hasTable('insurance_policies') ? DB::table('insurance_policies')->whereIn('insurance_quote_id', $quoteIds)->pluck('id')->all() : [];
        $conversationIds = Schema::hasTable('conversation_participants') ? DB::table('conversation_participants')->whereIn('user_id', $userIds)->pluck('conversation_id')->all() : [];

        Schema::disableForeignKeyConstraints();
        try {
            $del = function (string $table, string $column, array $ids): void {
                if ($ids !== [] && Schema::hasTable($table)) {
                    DB::table($table)->whereIn($column, $ids)->delete();
                }
            };
            $nullify = function (string $table, string $column, array $ids): void {
                if ($ids !== [] && Schema::hasTable($table)) {
                    DB::table($table)->whereIn($column, $ids)->update([$column => null]);
                }
            };

            $del('insurance_claims', 'insurance_policy_id', $policyIds);
            $del('insurance_claims', 'dispute_id', $disputeIds);
            $del('insurance_policies', 'id', $policyIds);
            $del('insurance_quotes', 'id', $quoteIds);
            $del('dispute_allocations', 'dispute_id', $disputeIds);
            $del('dispute_allocations', 'beneficiary_user_id', $userIds);
            $del('disputes', 'id', $disputeIds);
            $del('reviews', 'load_id', $loadIds);
            $del('reviews', 'reviewer_id', $userIds);
            $del('reviews', 'reviewee_id', $userIds);
            $del('invoice_attempts', 'invoice_id', Schema::hasTable('invoices') ? DB::table('invoices')->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('payment_order_id', $orderIds ?: [0])->orWhereIn('payout_id', $payoutIds ?: [0]))->pluck('id')->all() : []);
            if (Schema::hasTable('invoices')) {
                DB::table('invoices')->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('payment_order_id', $orderIds ?: [0])->orWhereIn('payout_id', $payoutIds ?: [0]))->delete();
            }
            $del('payout_attempts', 'payout_id', $payoutIds);
            $del('payouts', 'id', $payoutIds);
            $del('subscription_cycles', 'payment_order_id', $orderIds);
            if (Schema::hasTable('subscriptions')) {
                $subIds = DB::table('subscriptions')->whereIn('user_id', $userIds)->pluck('id')->all();
                $del('subscription_cycles', 'subscription_id', $subIds);
                $del('subscriptions', 'id', $subIds);
            }
            $del('coupon_redemptions', 'user_id', $userIds);
            $del('coupon_redemptions', 'payment_order_id', $orderIds);
            $del('payment_events', 'payment_order_id', $orderIds);
            $del('payment_orders', 'id', $orderIds);
            $del('ledger_entries', 'ledger_account_id', $ledgerAccountIds);
            $del('ledger_accounts', 'id', $ledgerAccountIds);
            $del('bank_accounts', 'user_id', $userIds);
            $del('shipment_evidence', 'shipment_id', $shipmentIds);
            $del('shipment_evidence', 'uploaded_by', $userIds);
            $del('driver_locations', 'driver_profile_id', $driverIds);
            $nullify('driver_locations', 'shipment_id', $shipmentIds);
            $del('shipments', 'id', $shipmentIds);
            $del('offers', 'id', $offerIds);
            $del('loads', 'id', $loadIds);
            $del('messages', 'sender_id', $userIds);
            $del('messages', 'conversation_id', $conversationIds);
            $del('conversation_participants', 'conversation_id', $conversationIds);
            $del('conversations', 'id', $conversationIds);
            $nullify('support_ticket_messages', 'sender_id', $userIds);
            $nullify('support_tickets', 'user_id', $userIds);
            $nullify('support_tickets', 'assigned_to', $userIds);
            $nullify('activity_logs', 'user_id', $userIds);
            $nullify('setting_revisions', 'user_id', $userIds);
            $nullify('kyc_documents', 'reviewed_by', $userIds);
            $nullify('driver_profiles', 'kyc_verified_by', $userIds);
            $nullify('cargo_owner_profiles', 'kyc_verified_by', $userIds);
            $del('kyc_documents', 'user_id', $userIds);
            $del('user_consents', 'user_id', $userIds);
            $del('saved_addresses', 'user_id', $userIds);
            $del('driver_filter_presets', 'driver_profile_id', $driverIds);
            $del('driver_vehicles', 'driver_profile_id', $driverIds);
            $del('driver_profiles', 'id', $driverIds);
            $del('cargo_owner_profiles', 'id', $ownerIds);
            if (Schema::hasTable('model_has_roles')) {
                DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            }
            $del('users', 'id', $userIds);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->line(sprintf('  Silindi: %d kullanıcı, %d ilan, %d teklif, %d sevkiyat', count($userIds), count($loadIds), count($offerIds), count($shipmentIds)));
    }

    private function createDemo(string $email, string $phone): void
    {
        [$first, $last] = array_pad(explode(' ', trim((string) $this->option('demo-name')), 2), 2, 'Demo');

        $user = User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make((string) $this->option('demo-password')),
            'current_role' => 'cargo_owner',
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        foreach (['cargo_owner', 'driver'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $user->syncRoles(['cargo_owner', 'driver']);

        CargoOwnerProfile::create([
            'user_id' => $user->id,
            'type' => 'individual',
            'tc_no' => '10000000146', // biçimsel olarak geçerli deneme numarası
            'nvi_verified' => false,
            'gib_verified' => false,
            'kyc_status' => 'approved',
            'kyc_submitted_at' => now(),
            'kyc_verified_at' => now(),
            'kyc_notes' => 'Deneme hesabı: belgeler komutla onaylandı.',
        ]);

        $driver = DriverProfile::create([
            'user_id' => $user->id,
            'kyc_status' => 'approved',
            'kyc_submitted_at' => now(),
            'kyc_verified_at' => now(),
            'kyc_notes' => 'Deneme hesabı: belgeler komutla onaylandı.',
            'preferences' => ['notify_new_loads' => true, 'notify_offer_results' => true, 'preferred_routes' => 'Ankara - İzmir'],
        ]);

        DriverVehicle::create([
            'driver_profile_id' => $driver->id,
            'plate' => '06DMO001',
            'vehicle_type' => 'tir',
            'is_active' => true,
        ]);

        foreach (['terms', 'kvkk'] as $consent) {
            UserConsent::create([
                'user_id' => $user->id,
                'consent_type' => $consent,
                'document_version' => (string) config('company.legal_document_version', '1.0'),
                'granted' => true,
                'recorded_at' => now(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'artisan demo:reset',
            ]);
        }

        $this->line("  Oluşturuldu: #{$user->id} {$user->first_name} {$user->last_name} · {$email} · {$phone} · TIR 06DMO001 · belgeler onaylı");
    }
}
