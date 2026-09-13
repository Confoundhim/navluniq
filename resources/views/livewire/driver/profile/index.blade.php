<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\User;
use App\Models\DriverProfile;
use App\Models\CargoOwnerProfile;
use App\Mail\AdminOtpMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

new
#[Layout('components.layouts.driver')]
#[Title('Şoför Profili, Akıllı Filtreler & KYC')]
class extends Component {
    // Kişisel Bilgiler
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $phone = '';

    // Favori Akıllı Filtre Ayarları
    public string $blacklist_cities = 'İstanbul (Avrupa İçi)'; // Rota Kara Listesi
    public string $priority_routes = 'Ankara → İzmir'; // Öncelikli Rotalarım
    public string $min_price_filter = '10000'; // Taban Fiyat Limiti
    public string $max_weight_filter = '25000'; // Maksimum Tonaj Kapasitesi
    public string $active_template = 'Marmara & Ege Turum'; // Hızlı Şablon Kaydı

    // Bildirim Tercihleri (Toggles)
    public bool $notify_new_match = true;
    public bool $notify_return_radar = true;
    public bool $notify_sms = true;

    // Çift Rol Geçişi (OTP Modalı)
    public bool $switchModalOpen = false;
    public string $otp_input = '';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user) {
            $this->first_name = $user->first_name ?? '';
            $this->last_name = $user->last_name ?? '';
            $this->email = $user->email ?? '';
            $this->phone = $user->phone ?? '';
        }
    }

    public function updateProfile(): void
    {
        $this->validate([
            'first_name' => 'required|min:2',
            'last_name' => 'required|min:2',
            'email' => 'required|email',
            'phone' => 'required|min:10',
        ]);

        $user = Auth::user();
        if ($user) {
            $user->update([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'phone' => $this->phone,
            ]);

            session()->flash('success_message', 'Profil bilgileriniz ve akıllı filtre tercihleriniz başarıyla güncellendi.');
        }
    }

    public function openRoleSwitch(): void
    {
        $user = Auth::user();
        if (!$user) return;

        $this->otp_input = '';
        $otpCode = (string) random_int(100000, 999999);

        // Kodu hashleyerek veritabanında 5 dakika süreli saklıyoruz
        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        // E-Posta servisi ile doğrulama kodunu gönderiyoruz
        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
        } catch (\Exception $e) {
            Log::error("Yük Sahibi Geçişi OTP Hatası: " . $e->getMessage());
        }

        $this->switchModalOpen = true;
    }

    public function executeRoleSwitch(): void
    {
        $this->validate([
            'otp_input' => 'required|numeric|digits:6',
        ], [
            'otp_input.required' => 'Doğrulama kodunu girmek zorunludur.',
            'otp_input.digits' => 'Kod tam olarak 6 haneli olmalıdır.',
        ]);

        $currentUser = Auth::user();
        if (!$currentUser) return;

        // Kodun süresi dolmuş mu kontrolü
        if (!$currentUser->otp_expires_at || now()->greaterThan($currentUser->otp_expires_at)) {
            $this->addError('otp_input', 'Kodun geçerlilik süresi dolmuş. Modalı kapatıp tekrar kod isteyiniz.');
            return;
        }

        // Girilen kod veritabanındaki hash ile eşleşiyor mu?
        if (!Hash::check($this->otp_input, $currentUser->otp_code)) {
            $this->addError('otp_input', 'Girdiğiniz doğrulama kodu hatalıdır.');
            return;
        }

        // Başarılı doğrulamada OTP bilgilerini sıfırlıyoruz
        $currentUser->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        // 1. Aynı telefonla kayıtlı ayrı bir Yük Sahibi hesabı var mı?
        $otherUser = User::where('phone', $currentUser->phone)
            ->where('id', '!=', $currentUser->id)
            ->where(function ($q) {
                $q->where('current_role', 'cargo_owner')
                  ->orWhereHas('cargoOwnerProfile');
            })->first();

        if ($otherUser) {
            // Ayrı hesap varsa direkt ona oturum açıyoruz
            $otherUser->update(['current_role' => 'cargo_owner']);
            Auth::login($otherUser, true);
        } else {
            // Tek hesap üzerinden profiller yönetiliyorsa
            CargoOwnerProfile::firstOrCreate(
                ['user_id' => $currentUser->id],
                ['type' => 'individual', 'kyc_status' => 'unsubmitted']
            );
            $currentUser->switchRole('cargo_owner');
        }

        $this->switchModalOpen = false;

        // Doğrudan Yük Sahibi Yönetim Paneline tam sayfa yönlendirmesi
        $this->redirect('/panel/yuk-sahibi', navigate: false);
    }
}; ?>

<div class="space-y-6">

    <!-- Başarı Bildirimi -->
    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success_message') }}</span>
            </div>
        </div>
    @endif

    <!-- Üst Başlık & Çift Rol Geçiş Butonu (SADECE ŞART SAĞLANDIĞINDA GÖRÜNÜR) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Sürücü Profili, Akıllı Filtreler & Belgeler</h2>
            <p class="text-xs text-neutral-400 mt-1">Yasal KYC belgeleriniz, rota filtreleriniz ve güvenlik tercihleri.</p>
        </div>

        @if(auth()->user()?->hasCargoOwnerAccountWithSamePhone())
            <button type="button" wire:click="openRoleSwitch" class="px-5 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 border border-neutral-700/80 text-white font-bold text-xs shadow-lg transition-all flex items-center gap-2 group active:scale-95">
                <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                <span>Yük Sahibi Moduna Güvenli Geçiş</span>
                <svg class="w-4 h-4 text-neutral-500 group-hover:text-neutral-300 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
            </button>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Sol 2 Kolon: Hesap & Favori Akıllı Filtreler -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Kişisel Bilgiler -->
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Sürücü Hesap Bilgileri</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Ad <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="first_name" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        @error('first_name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Soyad <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="last_name" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        @error('last_name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">E-Posta Adresi <span class="text-brand-500">*</span></label>
                        <input type="email" wire:model="email" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        @error('email') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Telefon Numarası <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="phone" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono focus:border-brand-500 focus:outline-none">
                        @error('phone') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <!-- FAVORİ AKILLI FİLTRE AYARLARI (ROTA KARA LİSTESİ & ŞABLONLAR) -->
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-neutral-800 pb-3">
                    <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Favori Akıllı Filtre Ayarlarım</h3>
                    <span class="text-[10px] text-brand-400 font-mono">Zaman Tasarrufu Modülü</span>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Rota Kara Listesi (Hariç Tutulan Şehir/Bölgeler)</label>
                        <input type="text" wire:model="blacklist_cities" placeholder="Örn: İstanbul (Avrupa İçi), Trabzon..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        <span class="text-[10px] text-neutral-500 mt-1 block">Bu bölgelere giden yükler listenizde filtrelenir ve size gösterilmez.</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Öncelikli Rotalarım</label>
                            <input type="text" wire:model="priority_routes" placeholder="Örn: Ankara → İzmir" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Hızlı Şablon Adı</label>
                            <input type="text" wire:model="active_template" placeholder="Örn: Marmara & Ege Turum" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-brand-400 font-bold focus:border-brand-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Taban Fiyat Limiti (₺)</label>
                            <input type="number" wire:model="min_price_filter" placeholder="Örn: 10000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono focus:border-brand-500 focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Maksimum Tonaj Kapasitesi (Kg)</label>
                            <input type="number" wire:model="max_weight_filter" placeholder="Örn: 25000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono focus:border-brand-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <div class="pt-2 flex justify-end">
                    <button type="button" wire:click="updateProfile" class="px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all">
                        Tüm Tercihleri Kaydet
                    </button>
                </div>
            </div>

        </div>

        <!-- Sağ 1 Kolon: Yasal Belgeler & 15 Gün Kala Otomatik Uyarı Rozetleri -->
        <div class="space-y-6">

            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-neutral-800 pb-3">
                    <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Yasal KYC Belgelerim</h3>
                    <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 text-[10px] font-bold">
                        ✓ AI Onaylı
                    </span>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 flex items-center justify-between">
                        <div>
                            <div class="text-white font-semibold">Sürücü Belgesi (Ehliyet)</div>
                            <div class="text-[10px] text-neutral-500">Geçerlilik: 2030</div>
                        </div>
                        <span class="text-emerald-400 text-xs font-bold">Aktif</span>
                    </div>

                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 flex items-center justify-between">
                        <div>
                            <div class="text-white font-semibold">SRC 3 / SRC 4 Belgesi</div>
                            <div class="text-[10px] text-neutral-500">Geçerlilik: 2028</div>
                        </div>
                        <span class="text-emerald-400 text-xs font-bold">Aktif</span>
                    </div>

                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 flex items-center justify-between">
                        <div>
                            <div class="text-white font-semibold">Psikoteknik Raporu</div>
                            <div class="text-[10px] text-neutral-500">Geçerlilik: 2027</div>
                        </div>
                        <span class="text-emerald-400 text-xs font-bold">Aktif</span>
                    </div>

                    <div class="p-3 bg-neutral-950 rounded-xl border border-amber-500/30 flex items-center justify-between">
                        <div>
                            <div class="text-white font-semibold">Taşıyıcı Sorumluluk Sigortası</div>
                            <div class="text-[10px] text-amber-400 font-medium">Bitişe 22 gün kaldı</div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400 text-[10px] font-bold">Yenileme Yakın</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-neutral-950 border border-neutral-800 text-[11px] text-neutral-400 leading-relaxed">
                    💡 Sistem, sigorta veya yetki belgenizin bitiş tarihine <b>15 gün kala</b> SMS ile otomatik hatırlatma gönderir.
                </div>
            </div>

        </div>

    </div>

    <!-- Çift Rol Geçiş Modalı (E-Posta OTP) -->
    @if($switchModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('switchModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-blue-500/10 text-blue-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </span>
                        <span>Yük Sahibi Moduna Güvenli Geçiş</span>
                    </h3>
                    <button wire:click="$set('switchModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="text-xs text-neutral-400 leading-relaxed">
                    Aynı telefon numarasına kayıtlı Yük Sahibi hesabınıza geçebilmeniz için <strong class="text-white">{{ $email }}</strong> e-posta adresinize 6 haneli doğrulama kodu gönderildi.
                </p>

                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-neutral-300">6 Haneli Doğrulama Kodu</label>
                    <input type="text" wire:model.defer="otp_input" maxlength="6" placeholder="000000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl py-3 text-center text-lg font-mono font-bold tracking-widest text-white focus:border-brand-500 focus:outline-none">
                    @error('otp_input') <span class="text-rose-500 text-xs mt-1 block text-center">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('switchModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        İptal
                    </button>
                    <button type="button" wire:click="executeRoleSwitch" class="flex-1 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold shadow-lg shadow-blue-600/20 transition-all flex justify-center items-center">
                        <span wire:loading.remove wire:target="executeRoleSwitch">Doğrula & Geçiş Yap</span>
                        <span wire:loading wire:target="executeRoleSwitch">Geçiş Yapılıyor...</span>
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
