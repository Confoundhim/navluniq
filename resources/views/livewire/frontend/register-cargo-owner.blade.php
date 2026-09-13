<?php

use App\Models\CargoOwnerProfile;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\GibService;
use App\Services\NviService;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public int $step = 1;

    #[Locked]
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
    public bool $taxNoFormatValid = false;

    public function updatedTaxNo(string $value): void
    {
        $this->taxNoFormatValid = false;
        $this->apiStatusMessage = null;

        if (strlen($value) === 10) {
            $result = (new GibService)->verifyTax($value);
            $this->taxNoFormatValid = (bool) $result['is_match'];
            $this->apiStatusMessage = $this->taxNoFormatValid
                ? 'Vergi kimlik numarası biçimi geçerli. Resmi unvan KYC belge kontrolünde teyit edilecektir.'
                : 'Vergi kimlik numarası geçersiz görünüyor, lütfen kontrol edin.';
        }
    }

    public function register(): void
    {
        $rules = [
            'firstName' => 'required|string|min:2|max:80',
            'lastName' => 'required|string|min:2|max:80',
            'email' => 'required|email|max:255',
            'phone' => ['required', 'string', Phone::RULE],
            'password' => 'required|string|min:12|max:255|confirmed',
            'acceptTerms' => 'accepted',
        ];

        if ($this->type === 'individual') {
            $rules['tcNo'] = ['required', 'digits:11', Rule::unique('cargo_owner_profiles', 'tc_no')];
            $rules['birthYear'] = 'required|digits:4|integer|min:1920|max:'.(date('Y') - 18);
        } else {
            $rules['taxNo'] = ['required', 'digits:10', Rule::unique('cargo_owner_profiles', 'tax_no')];
            $rules['companyTitle'] = 'required|string|min:3|max:255';
            $rules['taxOffice'] = 'nullable|string|max:120';
        }

        $this->validate($rules, [
            'acceptTerms.accepted' => 'Sözleşmeleri ve KVKK metnini onaylamadan kayıt olamazsınız.',
            'password.confirmed' => 'Girdiğiniz şifreler birbiriyle eşleşmiyor.',
            'phone.regex' => 'Geçerli bir cep telefonu numarası girin (05XX XXX XX XX).',
            'birthYear.max' => 'Platforma 18 yaşından büyükler kayıt olabilir.',
            'birthYear.required' => 'NVİ doğrulaması için doğum yılınızı girmeniz gerekmektedir.',
            'tcNo.unique' => 'Bu T.C. kimlik numarası ile zaten bir yük sahibi profili var.',
            'taxNo.unique' => 'Bu vergi kimlik numarası ile zaten bir yük sahibi profili var.',
        ]);

        if ($this->type === 'corporate' && ! (new GibService)->verifyTax($this->taxNo)['is_match']) {
            $this->addError('taxNo', 'Vergi kimlik numarası geçersiz.');

            return;
        }

        $firstName = trim($this->firstName);
        $lastName = trim($this->lastName);
        $email = mb_strtolower(trim($this->email));
        $phone = Phone::normalize($this->phone);

        $userByEmail = User::withTrashed()->where('email', $email)->first();
        $userByPhone = User::withTrashed()->whereIn('phone', Phone::variants($phone))->first();

        if ($userByEmail && $userByPhone && $userByEmail->id !== $userByPhone->id) {
            $this->addError('email', 'Girdiğiniz e-posta ve telefon numarası sistemde farklı hesaplara ait.');

            return;
        }

        $existingUser = $userByEmail ?? $userByPhone;

        if ($existingUser?->trashed()) {
            $this->addError('email', 'Bu bilgilerle kapatılmış bir hesap var. Lütfen destek ekibiyle iletişime geçin.');

            return;
        }

        $isDraft = $existingUser && $existingUser->email_verified_at === null && ! $existingUser->is_active;

        if ($existingUser && ! $isDraft) {
            if (! Hash::check($this->password, $existingUser->password)) {
                $this->addError('password', 'Bu bilgiler sistemde kayıtlı. Yük sahibi rolü eklemek için mevcut şifrenizi girin.');

                return;
            }
            if ($existingUser->banned_at !== null || ! $existingUser->is_active || in_array($existingUser->current_role, ['admin', 'super_admin'], true)) {
                $this->addError('email', 'Bu hesaba yeni rol eklenemez.');

                return;
            }
            if ($existingUser->cargoOwnerProfile()->exists()) {
                $this->addError('email', 'Bu hesapla zaten bir yük sahibi profili var. Doğrudan giriş yapabilirsiniz.');

                return;
            }
        }

        $nviVerified = false;
        if ($this->type === 'individual') {
            $nviVerified = (bool) ((new NviService)->verify($this->tcNo, $firstName, $lastName, $this->birthYear)['is_match'] ?? false);
        }

        $user = DB::transaction(function () use ($existingUser, $isDraft, $firstName, $lastName, $email, $phone, $nviVerified) {
            if ($existingUser && ! $isDraft) {
                $user = $existingUser;
            } elseif ($isDraft) {
                $user = $existingUser;
                $user->cargoOwnerProfile()->delete();
                $user->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $this->password,
                    'current_role' => 'cargo_owner',
                ]);
            } else {
                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $this->password,
                    'current_role' => 'cargo_owner',
                    'is_active' => false,
                ]);
            }

            CargoOwnerProfile::create([
                'user_id' => $user->id,
                'type' => $this->type,
                'tc_no' => $this->type === 'individual' ? $this->tcNo : null,
                'tax_no' => $this->type === 'corporate' ? $this->taxNo : null,
                'company_title' => $this->type === 'corporate' ? trim($this->companyTitle) : null,
                'tax_office' => $this->type === 'corporate' ? (trim($this->taxOffice) ?: null) : null,
                'nvi_verified' => $nviVerified,
                'gib_verified' => false,
                'kyc_status' => 'unsubmitted',
            ]);

            $user->syncRoles(array_unique([...$user->getRoleNames()->all(), 'cargo_owner']));

            foreach (['terms', 'kvkk'] as $consent) {
                UserConsent::create([
                    'user_id' => $user->id,
                    'consent_type' => $consent,
                    'document_version' => (string) config('company.legal_document_version', '1.0'),
                    'granted' => true,
                    'recorded_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
                ]);
            }

            return $user;
        });

        $this->registeredUserId = $user->id;
        $this->sendOtp($user);
    }

    private function sendOtp(User $user): void
    {
        $error = app(OtpService::class)->send($user, 'Yük sahibi hesabınızı doğrulamak', 'register');

        if ($error) {
            $this->addError('otp', $error);
            $this->step = 2;

            return;
        }

        session()->flash('otp_message', "6 haneli doğrulama kodu {$user->email} adresine gönderildi.");
        $this->step = 2;
    }

    public function resendOtp(): void
    {
        $user = $this->registeredUserId ? User::find($this->registeredUserId) : null;
        if ($user) {
            $this->sendOtp($user);
        }
    }

    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|digits:6'], [
            'otp.required' => 'Doğrulama kodunu girmek zorunludur.',
            'otp.digits' => 'Kod 6 haneli olmalıdır.',
        ]);

        $user = $this->registeredUserId ? User::find($this->registeredUserId) : null;
        if (! $user || $user->banned_at !== null || in_array($user->current_role, ['admin', 'super_admin'], true)) {
            $this->addError('otp', 'Doğrulama başlatılamadı. Lütfen kayıt formunu yeniden doldurun.');

            return;
        }

        if ($error = app(OtpService::class)->verify($user, $this->otp, 'register')) {
            $this->addError('otp', $error);

            return;
        }

        $user->forceFill([
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'current_role' => 'cargo_owner',
            'last_login_at' => now(),
        ])->save();

        Auth::login($user, true);
        request()->session()->regenerate();
        session()->flash('success', 'Yük sahibi hesabınız doğrulandı.');

        return $this->redirect(route('cargo-owner.dashboard'), navigate: true);
    }

    public function backToForm(): void
    {
        $this->reset(['otp']);
        $this->step = 1;
    }
}; ?>


<div class="max-w-xl mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">

        @if($step === 1)
            <div class="text-center space-y-2">
                <span class="text-xs font-black text-brand-500 uppercase tracking-widest">YÜK SAHİBİ KAYIT FORMU</span>
                <h1 class="text-2xl sm:text-3xl font-black text-neutral-950 dark:text-white">NavlunIQ'ya Katılın</h1>
                <p class="text-xs text-neutral-400">İlanlarınızı oluşturun, belgeleri doğrulanmış şoförlerden teklif toplayın.</p>
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
                <div class="p-3 text-xs rounded-xl border {{ $taxNoFormatValid ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600' : 'bg-amber-500/10 border-amber-500/20 text-amber-600' }} animate-fade-in font-medium">
                    {{ $apiStatusMessage }}
                </div>
            @endif

            <form wire:submit.prevent="register" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Ad</label>
                        <input type="text" wire:model="firstName" placeholder="Adınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('firstName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Soyad</label>
                        <input type="text" wire:model="lastName" placeholder="Soyadınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('lastName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">E-Posta Adresi</label>
                        <input type="email" wire:model="email" placeholder="ornek@sirket.com" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('email') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Cep Telefonu</label>
                        <input type="text" wire:model="phone" placeholder="05XXXXXXXXX" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('phone') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if($type === 'individual')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2 space-y-1.5">
                            <label class="font-semibold text-neutral-500">T.C. Kimlik Numarası</label>
                            <input type="text" wire:model="tcNo" maxlength="11" placeholder="11 Haneli T.C. Kimlik No" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                            @error('tcNo') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Doğum Yılı</label>
                            <input type="text" wire:model="birthYear" maxlength="4" placeholder="1990" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                            @error('birthYear') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Vergi Kimlik Numarası (VKN)</label>
                        <input type="text" wire:model.live="taxNo" maxlength="10" placeholder="10 haneli VKN" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 font-mono text-neutral-900 dark:text-white">
                        @error('taxNo') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Firma Resmi Unvanı</label>
                            <input type="text" wire:model="companyTitle" placeholder="Vergi levhasındaki unvan" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                            @error('companyTitle') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Vergi Dairesi</label>
                            <input type="text" wire:model="taxOffice" placeholder="Örn. Kızılbey" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Giriş Şifresi</label>
                        <input type="password" wire:model="password" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                        @error('password') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Şifre Tekrarı</label>
                        <input type="password" wire:model="password_confirmation" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-start space-x-2 cursor-pointer">
                        <input type="checkbox" wire:model="acceptTerms" class="w-4 h-4 mt-0.5 accent-brand-500 rounded">
                        <span class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">
                            <a href="{{ route('contracts', 'kullanici-sozlesmesi') }}" target="_blank" class="underline hover:text-black dark:hover:text-white font-semibold">Kullanıcı Sözleşmesini</a>,
                            <a href="{{ route('contracts', 'kvkk') }}" target="_blank" class="underline hover:text-black dark:hover:text-white font-semibold">KVKK Metnini</a> okudum ve kabul ediyorum.
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
                    <input type="text" wire:model="otp" maxlength="6" placeholder="000000" class="w-full tracking-[0.5em] text-center p-3.5 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 text-neutral-900 dark:text-white text-xl font-mono font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                    @error('otp') <span class="text-red-500 text-[10px] text-center block">{{ $message }}</span> @enderror

                    <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-bold flex justify-center items-center">
                        <span wire:loading.remove wire:target="verifyOtp">Kodu Doğrula ve Panele Git</span>
                        <span wire:loading wire:target="verifyOtp">Hesap Doğrulanıyor...</span>
                    </button>

                    <div class="flex justify-between items-center pt-2">
                        <button type="button" wire:click="resendOtp" class="text-brand-500 font-bold hover:underline text-[11px]">
                            Tekrar Kod Gönder
                        </button>
                        <button type="button" wire:click="backToForm" class="text-neutral-400 hover:text-neutral-600 text-[11px]">
                            ← Bilgileri Düzenle
                        </button>
                    </div>
                </form>
            </div>
        @endif

    </div>
</div>
