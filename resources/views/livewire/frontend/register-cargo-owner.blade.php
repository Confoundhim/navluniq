<?php
// resources/views/livewire/auth/register-cargo-owner.blade.php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\CargoOwnerProfile;
use App\Services\NviService;
use App\Services\GibService;
use App\Mail\AdminOtpMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

new class extends Component {
    public int $step = 1;
    public ?int $registeredUserId = null;
    public string $otp = '';

    public string $type = 'individual';
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $acceptTerms = false;

    public string $tcNo = '';
    public string $birthYear = '';
    public string $taxNo = '';
    public string $companyTitle = '';
    public string $taxOffice = '';

    public ?string $apiStatusMessage = null;
    public bool $isApiVerified = false;

    public function updatedTaxNo($value)
    {
        if (strlen($value) === 10) {
            $gib = new GibService();
            $result = $gib->verifyTax($value);

            if ($result['is_match']) {
                $this->companyTitle = $result['company_title'] ?? '';
                $this->taxOffice = $result['tax_office'] ?? '';
                $this->isApiVerified = true;
                $this->apiStatusMessage = "GİB Doğrulandı: " . $result['company_title'];
            } else {
                $this->isApiVerified = false;
                $this->apiStatusMessage = "GİB Uyarısı: Vergi Kimlik Numarası bulunamadı.";
            }
        }
    }

    public function register()
    {
        $rules = [
            'firstName' => 'required|string|min:2',
            'lastName' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'required|string|min:10',
            'password' => 'required|string|min:12|confirmed',
            'acceptTerms' => 'accepted',
        ];

        if ($this->type === 'individual') {
            $rules['tcNo'] = 'required|digits:11';
            $rules['birthYear'] = 'required|digits:4|integer|min:1920|max:' . (date('Y') - 18);
        } else {
            $rules['taxNo'] = 'required|digits:10';
            $rules['companyTitle'] = 'required|string|min:3';
        }

        $this->validate($rules, [
            'acceptTerms.accepted' => 'Sözleşmeleri ve KVKK metnini onaylamadan kayıt olamazsınız.',
            'password.confirmed' => 'Girdiğiniz şifreler birbiriyle eşleşmiyor.',
            'birthYear.max' => 'Platformumuza 18 yaşından büyükler kayıt olabilir.',
            'birthYear.required' => 'NVİ doğrulaması için doğum yılınızı girmeniz gerekmektedir.',
        ]);

        $cleanFirstName = trim($this->firstName);
        $cleanLastName = trim($this->lastName);
        $cleanEmail = trim($this->email);

        $digitsOnly = preg_replace('/[^0-9]/', '', $this->phone);
        $cleanPhone = ltrim($digitsOnly, '0');
        if (str_starts_with($cleanPhone, '90') && strlen($cleanPhone) === 12) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        $userByEmail = User::where('email', $cleanEmail)->first();
        $userByPhone = User::where('phone', $cleanPhone)->orWhere('phone', '0' . $cleanPhone)->first();

        if ($userByEmail && $userByPhone && $userByEmail->id !== $userByPhone->id) {
            $this->addError('email', 'Girdiğiniz e-posta ve telefon numarası sistemde farklı hesaplara ait.');
            return;
        }

        $existingUser = $userByEmail ?? $userByPhone;

        if ($existingUser) {
            if (!Hash::check($this->password, $existingUser->password)) {
                $this->addError('password', 'Bu bilgiler sistemde kayıtlı. Yük Sahibi rolü eklemek için mevcut şifrenizi giriniz.');
                return;
            }
            if (CargoOwnerProfile::where('user_id', $existingUser->id)->exists()) {
                $this->addError('email', 'Bu hesapla zaten bir Yük Sahibi profili oluşturulmuş. Doğrudan giriş yapabilirsiniz.');
                return;
            }
        }

        $nviVerified = false;
        if ($this->type === 'individual') {
            $nvi = new NviService();
            $nviRes = $nvi->verify($this->tcNo, $cleanFirstName, $cleanLastName, $this->birthYear);
            $nviVerified = $nviRes['is_match'] ?? false;
        }

        $user = DB::transaction(function () use ($nviVerified, $existingUser, $cleanFirstName, $cleanLastName, $cleanEmail, $cleanPhone) {
            if ($existingUser) {
                $user = $existingUser;
                $user->update([
                    'current_role' => 'cargo_owner',
                    'phone' => $cleanPhone,
                ]);
            } else {
                $user = User::create([
                    'first_name' => $cleanFirstName,
                    'last_name' => $cleanLastName,
                    'email' => $cleanEmail,
                    'phone' => $cleanPhone,
                    'password' => Hash::make($this->password),
                    'current_role' => 'cargo_owner',
                    'is_active' => true,
                ]);
            }

            CargoOwnerProfile::create([
                'user_id' => $user->id,
                'type' => $this->type,
                'tc_no' => $this->type === 'individual' ? $this->tcNo : null,
                'tax_no' => $this->type === 'corporate' ? $this->taxNo : null,
                'company_title' => $this->type === 'corporate' ? $this->companyTitle : null,
                'tax_office' => $this->type === 'corporate' ? $this->taxOffice : null,
                'nvi_verified' => $nviVerified,
                'gib_verified' => $this->isApiVerified,
                'kyc_status' => 'pending',
            ]);

            return $user;
        });

        $this->registeredUserId = $user->id;
        $this->sendOtpEmail($user);
        $this->step = 2;
    }

    public function sendOtpEmail(User $user)
    {
        $otpCode = (string) random_int(100000, 999999);

        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
            session()->flash('otp_message', "6 haneli doğrulama kodu {$user->email} adresinize gönderildi.");
        } catch (\Exception $e) {
            Log::error("Yük Sahibi Kayıt OTP Hatası: " . $e->getMessage());
            $this->addError('otp', 'E-posta servisinde bir sorun oluştu. Lütfen tekrar deneyin.');
        }
    }

    public function resendOtp()
    {
        $user = User::find($this->registeredUserId);
        if ($user) {
            $this->sendOtpEmail($user);
        }
    }

    public function verifyOtp()
    {
        $this->validate([
            'otp' => 'required|numeric|digits:6',
        ], [
            'otp.required' => 'Doğrulama kodunu girmek zorunludur.',
            'otp.digits' => 'Kod 6 haneli olmalıdır.',
        ]);

        $user = User::find($this->registeredUserId);

        if (!$user || !$user->otp_expires_at || now()->greaterThan($user->otp_expires_at)) {
            $this->addError('otp', 'Doğrulama kodunun süresi dolmuş. Lütfen yeni kod isteyin.');
            return;
        }

        if (!Hash::check($this->otp, $user->otp_code)) {
            $this->addError('otp', 'Girdiğiniz doğrulama kodu hatalı.');
            return;
        }

        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null,
            'current_role' => 'cargo_owner',
        ]);

        Auth::login($user, true);

        session()->flash('success', 'Yük Sahibi hesabınız onaylandı! Paneldesiniz.');

        // Düz ve standart panel yönlendirmesi
        return redirect()->intended('/panel');
    }
}; ?>

<div class="max-w-xl mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">

        @if($step === 1)
            <div class="text-center space-y-2">
                <span class="text-xs font-black text-brand-500 uppercase tracking-widest">YÜK SAHİBİ KAYIT FORMU</span>
                <h1 class="text-2xl sm:text-3xl font-black text-neutral-950 dark:text-white">NavlunIQ'ya Katılın</h1>
                <p class="text-xs text-neutral-400">İlanlarınızı oluşturun, AI doğrulamalı profesyonel şoförlerden teklif toplayın.</p>
            </div>

            <div class="p-1 bg-neutral-100 dark:bg-neutral-900 rounded-2xl flex border border-neutral-200/40 text-xs">
                <button wire:click="$set('type', 'individual')" class="flex-1 py-2.5 rounded-xl font-bold transition-all {{ $type === 'individual' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
                    👤 Bireysel Yük Sahibi
                </button>
                <button wire:click="$set('type', 'corporate')" class="flex-1 py-2.5 rounded-xl font-bold transition-all {{ $type === 'corporate' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
                    🏢 Kurumsal Yük Sahibi
                </button>
            </div>

            @if($apiStatusMessage)
                <div class="p-3 text-xs rounded-xl border {{ $isApiVerified ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600' : 'bg-amber-500/10 border-amber-500/20 text-amber-600' }} animate-fade-in font-medium">
                    {{ $apiStatusMessage }}
                </div>
            @endif

            <form wire:submit.prevent="register" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Ad</label>
                        <input type="text" wire:model.defer="firstName" placeholder="Adınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('firstName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Soyad</label>
                        <input type="text" wire:model.defer="lastName" placeholder="Soyadınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('lastName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">E-Posta Adresi</label>
                        <input type="email" wire:model.defer="email" placeholder="ornek@sirket.com" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('email') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Cep Telefonu</label>
                        <input type="text" wire:model.defer="phone" placeholder="05XXXXXXXXX" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('phone') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if($type === 'individual')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2 space-y-1.5">
                            <label class="font-semibold text-neutral-500">T.C. Kimlik Numarası (NVİ Doğrulamalı)</label>
                            <input type="text" wire:model.defer="tcNo" maxlength="11" placeholder="11 Haneli T.C. Kimlik No" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                            @error('tcNo') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Doğum Yılı</label>
                            <input type="text" wire:model.defer="birthYear" maxlength="4" placeholder="1990" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                            @error('birthYear') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Vergi Kimlik Numarası (VKN - GİB Doğrulamalı)</label>
                        <input type="text" wire:model.live="taxNo" maxlength="10" placeholder="10 Haneli VKN (Örn: 6301481858)" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                        @error('taxNo') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Firma Resmi Unvanı</label>
                            <input type="text" wire:model.defer="companyTitle" placeholder="GİB'den otomatik çekilir" class="w-full p-3 bg-neutral-100 dark:bg-neutral-800 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl text-neutral-900 dark:text-white font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Vergi Dairesi</label>
                            <input type="text" wire:model.defer="taxOffice" placeholder="GİB'den otomatik çekilir" class="w-full p-3 bg-neutral-100 dark:bg-neutral-800 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl text-neutral-900 dark:text-white font-bold">
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Giriş Şifresi</label>
                        <input type="password" wire:model.defer="password" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('password') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Şifre Tekrarı</label>
                        <input type="password" wire:model.defer="password_confirmation" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-start space-x-2 cursor-pointer">
                        <input type="checkbox" wire:model.defer="acceptTerms" class="w-4 h-4 mt-0.5 accent-brand-500 rounded">
                        <span class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">
                            <a href="/sozlesmeler/kullanici-sozlesmesi" target="_blank" class="underline hover:text-black dark:hover:text-white font-semibold">Kullanıcı Sözleşmesini</a>,
                            <a href="/sozlesmeler/kvkk" target="_blank" class="underline hover:text-black dark:hover:text-white font-semibold">KVKK Metnini</a> okudum ve kabul ediyorum.
                        </span>
                    </label>
                    @error('acceptTerms') <span class="text-red-500 text-[10px] block mt-1">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-bold shadow-apple-md flex justify-center items-center">
                    <span wire:loading.remove wire:target="register">Yük Sahibi Kaydını Başlat (E-Posta OTP Al)</span>
                    <span wire:loading wire:target="register">Bilgiler Kaydediliyor...</span>
                </button>

                <div class="text-center pt-2">
                    <span class="text-neutral-400 text-[11px]">Zaten hesabınız var mı?</span>
                    <a href="{{ route('login') }}" class="text-brand-500 font-bold ml-1 hover:underline text-[11px]">Giriş Yap</a>
                </div>
            </form>

        @else
            <!-- 2. ADIM: E-POSTA OTP DOĞRULAMA -->
            <div class="space-y-5 animate-slide-up text-xs">
                <div class="text-center space-y-1">
                    <div class="w-12 h-12 bg-brand-500/10 text-brand-500 rounded-2xl flex items-center justify-center mx-auto mb-2 text-xl font-bold">
                        ✉️
                    </div>
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">E-Posta Doğrulama Kodu</h3>
                    <p class="text-[11px] text-neutral-400">Yük Sahibi hesabınızı onaylamak için e-postanıza gönderilen 6 haneli güvenlik kodunu girin.</p>
                </div>

                @if(session()->has('otp_message'))
                    <div class="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 p-3 rounded-xl text-center border border-emerald-500/20 font-medium">
                        {{ session('otp_message') }}
                    </div>
                @endif

                <form wire:submit.prevent="verifyOtp" class="space-y-4">
                    <input type="text" wire:model.defer="otp" maxlength="6" placeholder="000000" class="w-full tracking-[0.5em] text-center p-3.5 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 text-neutral-900 dark:text-white text-xl font-mono font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                    @error('otp') <span class="text-red-500 text-[10px] text-center block">{{ $message }}</span> @enderror

                    <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-bold flex justify-center items-center">
                        <span wire:loading.remove wire:target="verifyOtp">Kodu Doğrula ve Panele Git</span>
                        <span wire:loading wire:target="verifyOtp">Hesap Doğrulanıyor...</span>
                    </button>

                    <div class="flex justify-between items-center pt-2">
                        <button type="button" wire:click="resendOtp" class="text-brand-500 font-bold hover:underline text-[11px]">
                            Tekrar Kod Gönder
                        </button>
                        <button type="button" wire:click="$set('step', 1)" class="text-neutral-400 hover:text-neutral-600 text-[11px]">
                            ← Bilgileri Düzenle
                        </button>
                    </div>
                </form>
            </div>
        @endif

    </div>
</div>
