<?php

use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public int $step = 1;

    #[Locked]
    public ?int $registeredUserId = null;

    /** Doğrulanmış bir hesaba şoför rolü ekleniyor: profil ancak kod doğrulanınca açılır. */
    #[Locked]
    public bool $roleAddPending = false;

    public string $otp = '';

    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $acceptTerms = false;

    /** Ticari elektronik ileti onayı: ayrı ve işaretlenmemiş kutu (ETK/İYS); kayıt için zorunlu değildir. */
    public bool $acceptMarketing = false;

    public string $plate = '';
    public string $vehicleType = '';

    public array $availableVehicleTypes = [];

    public function mount(): void
    {
        $this->availableVehicleTypes = DriverVehicle::getVehicleTypes();
    }

    public function registerDriver(): void
    {
        $this->plate = DriverVehicle::normalizePlate($this->plate);

        $this->validate([
            'firstName' => 'required|string|min:2|max:80',
            'lastName' => 'required|string|min:2|max:80',
            'email' => 'required|email|max:255',
            'phone' => ['required', 'string', Phone::RULE],
            'password' => 'required|string|min:12|max:255|confirmed',
            'plate' => ['required', 'string', DriverVehicle::PLATE_RULE, Rule::unique('driver_vehicles', 'plate')->where(fn ($q) => $q->whereNotIn('driver_profile_id', $this->draftProfileIds()))],
            'vehicleType' => ['required', Rule::in(array_keys($this->availableVehicleTypes))],
            'acceptTerms' => 'accepted',
        ], [
            'acceptTerms.accepted' => 'Sözleşmeleri ve KVKK metnini onaylamadan kayıt olamazsınız.',
            'plate.unique' => 'Bu plaka zaten bir sürücü hesabına kayıtlı.',
            'plate.regex' => 'Plakayı 34ABC123 biçiminde girin.',
            'phone.regex' => 'Geçerli bir cep telefonu numarası girin (05XX XXX XX XX).',
            'password.confirmed' => 'Girdiğiniz şifreler eşleşmiyor.',
            'vehicleType.in' => 'Listeden bir araç türü seçin.',
        ]);

        $firstName = trim($this->firstName);
        $lastName = trim($this->lastName);
        $email = mb_strtolower(trim($this->email));
        $phone = Phone::normalize($this->phone);

        // Aynı IP'den saatte en çok 10 kayıt denemesi: taslak hesap ve kod e-postası bombardımanı kesilir.
        $registerKey = 'register:'.hash('sha256', (string) request()->ip());
        if (RateLimiter::tooManyAttempts($registerKey, 10)) {
            $this->addError('email', 'Çok fazla kayıt denemesi yapıldı. Lütfen bir süre sonra tekrar deneyin.');

            return;
        }
        RateLimiter::hit($registerKey, 3600);

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
            // Kayıt formu bir şifre deneme kapısı olmasın: giriş ekranıyla aynı sayaç (5 deneme/dk, hesap+IP).
            $loginKey = 'login:'.hash('sha256', mb_strtolower((string) $existingUser->email).'|'.request()->ip());
            if (RateLimiter::tooManyAttempts($loginKey, 5)) {
                $this->addError('password', 'Çok fazla deneme yapıldı. Lütfen bir dakika bekleyin.');

                return;
            }
            if (! Hash::check($this->password, $existingUser->password)) {
                RateLimiter::hit($loginKey, 60);
                $this->addError('password', 'Bu bilgiler sistemde kayıtlı. Şoför rolü eklemek için mevcut şifrenizi girin.');

                return;
            }
            RateLimiter::clear($loginKey);
            if ($existingUser->banned_at !== null || ! $existingUser->is_active || in_array($existingUser->current_role, ['admin', 'super_admin'], true)) {
                $this->addError('email', 'Bu hesaba yeni rol eklenemez.');

                return;
            }
            if ($existingUser->driverProfile()->exists()) {
                $this->addError('email', 'Bu hesapla zaten bir şoför profili var. Doğrudan giriş yapabilirsiniz.');

                return;
            }
        }

        if ($existingUser && ! $isDraft) {
            // Doğrulanmış hesap: şifre bilinse bile profil/araç yalnız e-posta kodu doğrulanınca açılır.
            $this->roleAddPending = true;
            $this->registeredUserId = $existingUser->id;
            $this->sendOtp($existingUser);

            return;
        }

        $user = DB::transaction(function () use ($existingUser, $isDraft, $firstName, $lastName, $email, $phone) {
            if ($isDraft) {
                $user = $existingUser;
                if ($profile = $user->driverProfile) {
                    $profile->vehicles()->withTrashed()->forceDelete();
                    $profile->forceDelete();
                }
                $user->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $this->password,
                    'current_role' => 'driver',
                ]);
            } else {
                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $this->password,
                    'current_role' => 'driver',
                    'is_active' => false,
                ]);
            }

            $this->attachDriverRole($user);

            return $user;
        });

        $this->registeredUserId = $user->id;
        $this->sendOtp($user);
    }

    /** Şoför profili, ilk araç, rol ve sözleşme onayları (işlem içinde çağrılır). */
    private function attachDriverRole(User $user): void
    {
        $driverProfile = DriverProfile::create([
            'user_id' => $user->id,
            'kyc_status' => 'unsubmitted',
        ]);

        DriverVehicle::create([
            'driver_profile_id' => $driverProfile->id,
            'plate' => $this->plate,
            'vehicle_type' => $this->vehicleType,
            'is_active' => true,
        ]);

        $user->syncRoles(array_unique([...$user->getRoleNames()->all(), 'driver']));

        UserConsent::recordRegistration($user);
    }

    /** Taslak (doğrulanmamış) hesaplara ait şoför profilleri; plaka benzersizliğinde hariç tutulur. */
    private function draftProfileIds(): array
    {
        return DriverProfile::query()->whereIn('user_id', User::query()->whereNull('email_verified_at')->where('is_active', false)->select('id'))->pluck('id')->all();
    }

    private function sendOtp(User $user): void
    {
        $error = app(OtpService::class)->send($user, 'Şoför hesabınızı doğrulamak', 'register');

        if ($error) {
            $this->addError('otp', $error);
        } else {
            session()->flash('otp_message', "6 haneli doğrulama kodu {$user->email} adresine gönderildi.");
        }

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

        if ($this->roleAddPending && ! $user->driverProfile()->exists()) {
            if (DriverVehicle::query()->where('plate', $this->plate)->whereNotIn('driver_profile_id', $this->draftProfileIds())->exists()) {
                $this->addError('otp', 'Bu plaka bu arada başka bir hesaba kayıt edildi. Lütfen formu yeniden doldurun.');

                return;
            }
            DB::transaction(fn () => $this->attachDriverRole($user));
        }

        $user->forceFill([
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'current_role' => 'driver',
            'last_login_at' => now(),
        ])->save();

        if ($this->acceptMarketing) {
            app(\App\Services\MarketingConsentService::class)->grant($user);
        }
        Auth::login($user, true);
        session()->regenerate();
        app(\App\Services\NotificationService::class)->notify($user, 'NavlunIQ\'ya hoş geldiniz',
            ['Şoför hesabınız doğrulandı. Teklif verebilmek için ehliyet, SRC, psikoteknik, ruhsat ve kimlikli selfie belgelerinizi yükleyin; ekibimiz genellikle 24 saat içinde inceler.',
             'Belgeleriniz onaylanınca ilan havuzundaki yüklere teklif verebilirsiniz; premium ile yeni ilanları herkesten '.(($lead = app(\App\Services\LoadReleaseService::class)->delayMinutes()) > 0 ? $lead.' dakika' : 'aynı anda').' önce görür ve anında bildirim alırsınız.'],
            route('driver.profile.index'), 'Belgelerimi yükle', 'welcome');
        session()->flash('success', 'Şoför hesabınız doğrulandı.');

        return $this->redirect(route('driver.dashboard'), navigate: true);
    }

    public function backToForm(): void
    {
        $this->reset(['otp', 'roleAddPending', 'registeredUserId']);
        $this->step = 1;
    }
}; ?>


<div class="max-w-xl mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">

        @if($step === 1)
            <div class="text-center space-y-2">
                <span class="text-xs font-black text-brand-500 uppercase tracking-widest">ŞOFÖR & TAŞIYICI KAYIT FORMU</span>
                <h1 class="text-2xl sm:text-3xl font-black text-neutral-950 dark:text-white">Boş Kilometreye Son Verin</h1>
                <p class="text-xs text-neutral-400">Kaydolun, belgelerinizi yükleyin, size uygun yüklere teklif verin.</p>
            </div>

            <form wire:submit.prevent="registerDriver" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="form-label">Ad</label>
                        <input type="text" wire:model="firstName" placeholder="Adınız" class="form-input">
                        @error('firstName') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1">
                        <label class="form-label">Soyad</label>
                        <input type="text" wire:model="lastName" placeholder="Soyadınız" class="form-input">
                        @error('lastName') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="form-label">E-Posta</label>
                        <input type="email" wire:model="email" placeholder="sofor@hotmail.com" class="form-input">
                        @error('email') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1">
                        <label class="form-label">Telefon Numarası</label>
                        <input type="text" wire:model="phone" placeholder="05XXXXXXXXX" class="form-input">
                        @error('phone') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 space-y-3">
                    <span class="font-bold text-brand-500 block">ARAÇ VE FİLO BİLGİLERİ</span>
                    <div class="space-y-1 sm:max-w-xs">
                        <label class="form-label">Araç Plakası</label>
                        <input type="text" wire:model="plate" placeholder="06ANK1920" class="form-input uppercase font-bold">
                        @error('plate') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-2">
                        <label class="form-label">Araç Türü <span class="font-normal text-neutral-400">(tek dokunuşla seçin)</span></label>
                        <x-vehicle-type-picker model="vehicleType" />
                        @error('vehicleType') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="form-label">Giriş Şifresi</label>
                        <input type="password" wire:model="password" placeholder="••••••••" class="form-input">
                        @error('password') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1">
                        <label class="form-label">Şifre Tekrarı</label>
                        <input type="password" wire:model="password_confirmation" placeholder="••••••••" class="form-input">
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
                    @error('acceptTerms') <span class="form-error">{{ $message }}</span> @enderror
                    <label class="flex items-start gap-3 cursor-pointer select-none mt-2">
                        <input type="checkbox" wire:model="acceptMarketing" class="w-4 h-4 mt-0.5 accent-brand-500 rounded">
                        <span class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">
                            <span class="font-semibold">Ticari elektronik ileti:</span> kampanya ve duyuru e-postaları almayı kabul ediyorum (isteğe bağlı; her iletideki bağlantıyla ya da profilimden istediğim an vazgeçebilirim).
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-primary w-full py-3">
                    <span wire:loading.remove wire:target="registerDriver">Şoför Kaydını Başlat (E-Posta OTP Al)</span>
                    <span wire:loading wire:target="registerDriver">Bilgiler Kaydediliyor...</span>
                </button>

                <div class="text-center pt-2">
                    <span class="text-neutral-400 text-[11px]">Zaten hesabınız var mı?</span>
                    <a href="{{ route('login') }}" class="text-brand-500 font-bold ml-1 hover:underline text-[11px]">Giriş Yap</a>
                </div>
            </form>

        @else
            <!-- 2. ADIM: E-POSTA OTP DOĞRULAMA -->
            <div class="space-y-5 animate-slide-up">
                <div class="text-center space-y-1">
                    <div class="w-12 h-12 bg-brand-500/10 text-brand-500 rounded-2xl flex items-center justify-center mx-auto mb-2 text-xl font-bold">
                        ✉️
                    </div>
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">E-Posta Doğrulama Kodu</h3>
                    <p class="text-[11px] text-neutral-400">Şoför hesabınızı aktif etmek için e-posta adresinize gönderilen 6 haneli güvenlik kodunu girin.</p>
                </div>

                @if(session()->has('otp_message'))
                    <div class="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 p-3 rounded-xl text-center border border-emerald-500/20 font-medium">
                        {{ session('otp_message') }}
                    </div>
                @endif

                <form wire:submit.prevent="verifyOtp" class="space-y-4">
                    <input type="text" wire:model="otp" maxlength="6" placeholder="000000" class="form-input tracking-[0.5em] text-center text-xl font-mono font-bold">
                    @error('otp') <span class="form-error">{{ $message }}</span> @enderror

                    <button type="submit" class="btn-primary w-full py-3">
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
