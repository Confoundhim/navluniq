<?php
// resources/views/livewire/auth/register-driver.blade.php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
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

    // Form Alanları
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $acceptTerms = false;

    // Araç Alanları
    public string $plate = '';
    public string $vehicleType = '';
    public string $brand = '';
    public string $model = '';

    public array $availableVehicleTypes = [];

    public function mount()
    {
        $this->availableVehicleTypes = DriverVehicle::getVehicleTypes();
    }

    public function registerDriver()
    {
        $this->validate([
            'firstName' => 'required|string|min:2',
            'lastName' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'required|string|min:10',
            'password' => 'required|string|min:12|confirmed',
            'plate' => 'required|string|min:5|unique:driver_vehicles,plate',
            'vehicleType' => 'required|string',
            'brand' => 'required|string',
            'model' => 'required|string',
            'acceptTerms' => 'accepted',
        ], [
            'acceptTerms.accepted' => 'Sözleşmeleri ve KVKK metnini onaylamadan kayıt olamazsınız.',
            'plate.unique' => 'Bu plaka zaten bir sürücü hesabına kayıtlı.',
            'password.confirmed' => 'Girdiğiniz şifreler eşleşmiyor.',
        ]);

        $cleanFirstName = trim($this->firstName);
        $cleanLastName = trim($this->lastName);
        $cleanEmail = trim($this->email);

        $digitsOnly = preg_replace('/[^0-9]/', '', $this->phone);
        $cleanPhone = ltrim($digitsOnly, '0');
        if (str_starts_with($cleanPhone, '90') && strlen($cleanPhone) === 12) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        $cleanPlate = strtoupper(str_replace(' ', '', $this->plate));

        $userByEmail = User::where('email', $cleanEmail)->first();
        $userByPhone = User::where('phone', $cleanPhone)->orWhere('phone', '0' . $cleanPhone)->first();

        if ($userByEmail && $userByPhone && $userByEmail->id !== $userByPhone->id) {
            $this->addError('email', 'Girdiğiniz e-posta ve telefon numarası sistemde farklı hesaplara ait.');
            return;
        }

        $existingUser = $userByEmail ?? $userByPhone;

        if ($existingUser) {
            if (!Hash::check($this->password, $existingUser->password)) {
                $this->addError('password', 'Bu bilgiler sistemde kayıtlı. Şoför rolü eklemek için mevcut şifrenizi giriniz.');
                return;
            }
            if (DriverProfile::where('user_id', $existingUser->id)->exists()) {
                $this->addError('email', 'Bu hesapla zaten bir Şoför profili oluşturulmuş. Lütfen doğrudan giriş yapın.');
                return;
            }
        }

        $user = DB::transaction(function () use ($existingUser, $cleanFirstName, $cleanLastName, $cleanEmail, $cleanPhone, $cleanPlate) {
            if ($existingUser) {
                $user = $existingUser;
                $user->update([
                    'current_role' => 'driver',
                    'phone' => $cleanPhone,
                ]);
            } else {
                $user = User::create([
                    'first_name' => $cleanFirstName,
                    'last_name' => $cleanLastName,
                    'email' => $cleanEmail,
                    'phone' => $cleanPhone,
                    'password' => Hash::make($this->password),
                    'current_role' => 'driver',
                    'is_active' => true,
                ]);
            }

            $driverProfile = DriverProfile::create([
                'user_id' => $user->id,
                'kyc_status' => 'pending',
                'ocr_data' => [
                    'ehliyet_name' => $cleanFirstName,
                    'ehliyet_surname' => $cleanLastName,
                    'ehliyet_class' => strtoupper($this->vehicleType),
                ]
            ]);

            DriverVehicle::create([
                'driver_profile_id' => $driverProfile->id,
                'plate' => $cleanPlate,
                'brand' => trim($this->brand),
                'model' => trim($this->model),
                'vehicle_type' => $this->vehicleType,
                'is_active' => true,
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
            Log::error("Şoför Kayıt OTP Hatası: " . $e->getMessage());
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
            'current_role' => 'driver',
        ]);

        Auth::login($user, true);

        session()->flash('success', 'Şoför hesabınız başarıyla doğrulandı! Paneldesiniz.');

        // Düz ve standart panel yönlendirmesi
        return redirect()->intended('/panel');
    }
}; ?>

<div class="max-w-xl mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">

        @if($step === 1)
            <div class="text-center space-y-2">
                <span class="text-xs font-black text-brand-500 uppercase tracking-widest">ŞOFÖR & TAŞIYICI KAYIT FORMU</span>
                <h1 class="text-2xl sm:text-3xl font-black text-neutral-950 dark:text-white">Boş Kilometreye Son Verin</h1>
                <p class="text-xs text-neutral-400">KYC belgelerinizi yükleyin, size en uygun yüklere anında teklif verin.</p>
            </div>

            <form wire:submit.prevent="registerDriver" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Ad</label>
                        <input type="text" wire:model.defer="firstName" placeholder="Adınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('firstName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Soyad</label>
                        <input type="text" wire:model.defer="lastName" placeholder="Soyadınız" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('lastName') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">E-Posta</label>
                        <input type="email" wire:model.defer="email" placeholder="sofor@hotmail.com" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('email') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Telefon Numarası</label>
                        <input type="text" wire:model.defer="phone" placeholder="05XXXXXXXXX" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('phone') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 space-y-3">
                    <span class="font-bold text-brand-500 block">ARAÇ VE FİLO BİLGİLERİ</span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="font-semibold text-neutral-500">Araç Plakası</label>
                            <input type="text" wire:model.defer="plate" placeholder="06ANK1920" class="w-full p-2.5 bg-white dark:bg-neutral-800 border border-neutral-200/40 rounded-xl uppercase font-bold text-neutral-900 dark:text-white">
                            @error('plate') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1">
                            <label class="font-semibold text-neutral-500">Araç Türü</label>
                            <select wire:model.defer="vehicleType" class="w-full p-2.5 bg-white dark:bg-neutral-800 border border-neutral-200/40 rounded-xl text-neutral-900 dark:text-white font-bold appearance-none">
                                <option value="">Seçiniz...</option>
                                @foreach($availableVehicleTypes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('vehicleType') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="font-semibold text-neutral-500">Marka</label>
                            <input type="text" wire:model.defer="brand" placeholder="Mercedes-Benz" class="w-full p-2.5 bg-white dark:bg-neutral-800 border border-neutral-200/40 rounded-xl text-neutral-900 dark:text-white">
                            @error('brand') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1">
                            <label class="font-semibold text-neutral-500">Model</label>
                            <input type="text" wire:model.defer="model" placeholder="Actros 1845" class="w-full p-2.5 bg-white dark:bg-neutral-800 border border-neutral-200/40 rounded-xl text-neutral-900 dark:text-white">
                            @error('model') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Giriş Şifresi</label>
                        <input type="password" wire:model.defer="password" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('password') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Şifre Tekrarı</label>
                        <input type="password" wire:model.defer="password_confirmation" placeholder="••••••••" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
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
            <div class="space-y-5 animate-slide-up text-xs">
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
