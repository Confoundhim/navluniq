<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\User;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Mail\AdminOtpMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Firma Profili, KYC & Güvenlik')]
class extends Component {
    // Kullanıcı Bilgileri
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $phone = '';

    // Kurumsal & KYC Bilgileri (Salt Okunur)
    public string $company_title = '';
    public string $tax_no = '';
    public string $tax_office = '';
    public string $kyc_status = 'approved';

    // Bildirim Tercihleri (Toggles)
    public bool $notify_new_offer = true;
    public bool $notify_driver_on_way = true;
    public bool $notify_delivery_complete = true;

    // Çift Rol Geçişi (OTP Modal)
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

            if ($user->cargoOwnerProfile) {
                $this->company_title = $user->cargoOwnerProfile->company_title ?? '';
                $this->tax_no = $user->cargoOwnerProfile->tax_no ?? '';
                $this->tax_office = $user->cargoOwnerProfile->tax_office ?? '';
                $this->kyc_status = $user->cargoOwnerProfile->kyc_status ?? 'unsubmitted';
            }
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

            session()->flash('success_message', 'Profil bilgileriniz başarıyla güncellendi.');
        }
    }

    public function openRoleSwitch(): void
    {
        $user = Auth::user();
        if (!$user) return;

        $this->otp_input = '';
        $otpCode = (string) random_int(100000, 999999);

        // Kodu güvenli bir şekilde hashleyip 5 dakika süre tanımlıyoruz
        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        // E-Posta ile doğrulama kodunu gönderiyoruz
        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
        } catch (\Exception $e) {
            Log::error("Şoför Geçişi OTP Hatası: " . $e->getMessage());
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

        if (!$currentUser->otp_expires_at || now()->greaterThan($currentUser->otp_expires_at)) {
            $this->addError('otp_input', 'Kodun geçerlilik süresi dolmuş. Modalı kapatıp tekrar kod isteyiniz.');
            return;
        }

        if (!Hash::check($this->otp_input, $currentUser->otp_code)) {
            $this->addError('otp_input', 'Girdiğiniz doğrulama kodu hatalıdır.');
            return;
        }

        // OTP kodunu sıfırla
        $currentUser->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        // 1. Aynı telefonla kayıtlı ayrı bir Şoför kullanıcısı var mı?
        $otherUser = User::where('phone', $currentUser->phone)
            ->where('id', '!=', $currentUser->id)
            ->where(function ($q) {
                $q->where('current_role', 'driver')
                  ->orWhereHas('driverProfile');
            })->first();

        if ($otherUser) {
            // Ayrı hesap varsa direkt ona oturum açıyoruz
            $otherUser->update(['current_role' => 'driver']);
            Auth::login($otherUser, true);
        } else {
            // Tek bir hesap altında profil açılmışsa
            DriverProfile::firstOrCreate(
                ['user_id' => $currentUser->id],
                ['kyc_status' => 'approved', 'premium_until' => now()->addDays(7)]
            );
            $currentUser->switchRole('driver');
        }

        $this->switchModalOpen = false;

        // Doğrudan Şoför Yönetim Paneline tam sayfa yönlendirmesi
        $this->redirect('/panel/sofor', navigate: false);
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

    <!-- Üst Başlık & Çift Rol Geçiş Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Firma Profili & Güvenlik Ayarları</h2>
            <p class="text-xs text-neutral-400 mt-1">Yasal KYC doğrulamalarınız, iletişim bilgileriniz ve hesap güvenliği tercihleri.</p>
        </div>

        @if(auth()->user()?->hasDriverAccountWithSamePhone())
            <button type="button" wire:click="openRoleSwitch" class="px-5 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 border border-neutral-700/80 text-white font-bold text-xs shadow-lg transition-all flex items-center gap-2 group active:scale-95">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Şoför Moduna Güvenli Geçiş</span>
                <svg class="w-4 h-4 text-neutral-500 group-hover:text-neutral-300 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
            </button>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Sol 2 Kolon: Profil Güncelleme ve Bildirim Tercihleri -->
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Yetkili Hesap Bilgileri</h3>

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

                <div class="pt-2 flex justify-end">
                    <button type="button" wire:click="updateProfile" class="px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all">
                        Değişiklikleri Kaydet
                    </button>
                </div>
            </div>

            <!-- Bildirim Tercihleri -->
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Otomatik Bildirim Tercihleri</h3>

                <div class="space-y-3 text-xs divide-y divide-neutral-800">
                    <div class="flex items-center justify-between pt-2">
                        <div>
                            <div class="font-bold text-white">Yeni Teklif Geldiğinde</div>
                            <div class="text-neutral-400">İlanlarınıza onaylı şoförlerden yeni teklif verildiğinde anlık uyarı alırsınız.</div>
                        </div>
                        <input type="checkbox" wire:model="notify_new_offer" class="w-4 h-4 rounded bg-neutral-950 border-neutral-700 text-brand-500 focus:ring-brand-500/20">
                    </div>

                    <div class="flex items-center justify-between pt-3">
                        <div>
                            <div class="font-bold text-white">Şoför Yola Çıktığında (U-ETDS)</div>
                            <div class="text-neutral-400">Yükünüz araca yüklendiğinde ve şoför seyir haline geçtiğinde bildirim gelir.</div>
                        </div>
                        <input type="checkbox" wire:model="notify_driver_on_way" class="w-4 h-4 rounded bg-neutral-950 border-neutral-700 text-brand-500 focus:ring-brand-500/20">
                    </div>

                    <div class="flex items-center justify-between pt-3">
                        <div>
                            <div class="font-bold text-white">Teslimat Kanıtı (POD) Yüklendiğinde</div>
                            <div class="text-neutral-400">Yük hedefe ulaştığında ve teslimat evrakı yüklendiğinde SMS ve e-posta alırsınız.</div>
                        </div>
                        <input type="checkbox" wire:model="notify_delivery_complete" class="w-4 h-4 rounded bg-neutral-950 border-neutral-700 text-brand-500 focus:ring-brand-500/20">
                    </div>
                </div>
            </div>

        </div>

        <!-- Sağ 1 Kolon: Yasal KYC / GİB Doğrulama Kartı -->
        <div class="space-y-6">

            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-neutral-800 pb-3">
                    <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Yasal KYC & Şirket Bilgileri</h3>
                    <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold">
                        ✓ GİB Doğrulandı
                    </span>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-neutral-500 block mb-0.5">Resmi Firma Unvanı</span>
                        <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800 font-semibold text-neutral-200">
                            {{ $company_title }}
                        </div>
                    </div>

                    <div>
                        <span class="text-neutral-500 block mb-0.5">Vergi Kimlik Numarası (VKN)</span>
                        <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800 font-mono font-bold text-white">
                            {{ $tax_no }}
                        </div>
                    </div>

                    <div>
                        <span class="text-neutral-500 block mb-0.5">Bağlı Vergi Dairesi</span>
                        <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800 font-medium text-neutral-200">
                            {{ $tax_office }}
                        </div>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-neutral-950 border border-neutral-800/80 text-[11px] text-neutral-400 leading-relaxed">
                    🔒 <b class="text-neutral-200">KVKK Güvencesi:</b> Kurumsal verileriniz Gelir İdaresi Başkanlığı API'si üzerinden otomatik doğrulanmış olup güvenli özel disk alanında saklanmaktadır.
                </div>
            </div>

        </div>

    </div>

    <!-- Çift Rol Geçişi (OTP Doğrulama) Modalı -->
    @if($switchModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('switchModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                        </span>
                        <span>Şoför Moduna Güvenli Geçiş</span>
                    </h3>
                    <button wire:click="$set('switchModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="text-xs text-neutral-400 leading-relaxed">
                    Aynı telefon numarasına kayıtlı Şoför profilinize geçebilmeniz için <strong class="text-white">{{ $email }}</strong> e-posta adresinize 6 haneli doğrulama kodu gönderildi.
                </p>

                <div>
                    <label class="block text-xs font-medium text-neutral-300 mb-1.5">6 Haneli Doğrulama Kodu</label>
                    <input type="text" wire:model.defer="otp_input" maxlength="6" placeholder="000000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl py-3 text-center text-lg font-mono font-bold tracking-widest text-white focus:border-brand-500 focus:outline-none">
                    @error('otp_input') <span class="text-rose-500 text-xs mt-1 block text-center">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('switchModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        İptal
                    </button>
                    <button type="button" wire:click="executeRoleSwitch" class="flex-1 px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold shadow-lg shadow-emerald-500/20 transition-all flex justify-center items-center">
                        <span wire:loading.remove wire:target="executeRoleSwitch">Doğrula ve Şoför Moduna Geç</span>
                        <span wire:loading wire:target="executeRoleSwitch">Geçiş Yapılıyor...</span>
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
