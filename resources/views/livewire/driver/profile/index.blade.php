<?php

use App\Models\KycDocument;
use App\Services\AccountService;
use App\Services\KycService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.driver')]
#[Title('Profil, Belgeler ve Güvenlik')]
class extends Component {
    use WithFileUploads;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public string $upload_type = '';

    public $upload_file = null;

    public string $delete_password = '';

    public string $delete_reason = '';

    public bool $deleteModalOpen = false;

    public bool $notify_new_loads = true;

    public bool $notify_offer_results = true;

    public string $preferred_routes = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->phone = Phone::format($user->phone);
        $this->upload_type = array_key_first($this->allowedTypes());

        $prefs = $user->driverProfile?->preferences ?? [];
        $this->notify_new_loads = (bool) ($prefs['notify_new_loads'] ?? true);
        $this->notify_offer_results = (bool) ($prefs['notify_offer_results'] ?? true);
        $this->preferred_routes = (string) ($prefs['preferred_routes'] ?? '');
    }

    public function updatePreferences(): void
    {
        $this->validate(['preferred_routes' => 'nullable|string|max:300']);

        Auth::user()->driverProfile?->update(['preferences' => [
            'notify_new_loads' => $this->notify_new_loads,
            'notify_offer_results' => $this->notify_offer_results,
            'preferred_routes' => trim($this->preferred_routes),
        ]]);

        session()->flash('success_message', 'Tercihleriniz kaydedildi.');
    }

    public function updateProfile(): void
    {
        $user = Auth::user();

        $this->validate([
            'first_name' => 'required|string|min:2|max:80',
            'last_name' => 'required|string|min:2|max:80',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', Phone::RULE],
        ], [
            'email.unique' => 'Bu e-posta adresi başka bir hesapta kullanılıyor.',
            'phone.regex' => 'Geçerli bir cep telefonu numarası girin.',
        ]);

        $phone = Phone::normalize($this->phone);
        if (\App\Models\User::query()->whereKeyNot($user->id)->whereIn('phone', Phone::variants($phone))->exists()) {
            $this->addError('phone', 'Bu telefon numarası başka bir hesapta kullanılıyor.');

            return;
        }

        $email = mb_strtolower(trim($this->email));
        $user->update([
            'first_name' => trim($this->first_name),
            'last_name' => trim($this->last_name),
            'email' => $email,
            'phone' => $phone,
            'email_verified_at' => $email === $user->email ? $user->email_verified_at : null,
            'phone_verified_at' => $phone === $user->phone ? $user->phone_verified_at : null,
        ]);

        session()->flash('success_message', 'Profil bilgileriniz güncellendi.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|string|min:12|max:255|confirmed',
        ], [
            'current_password.current_password' => 'Mevcut şifreniz hatalı.',
            'new_password.confirmed' => 'Yeni şifreler birbiriyle eşleşmiyor.',
        ]);

        Auth::user()->forceFill(['password' => $this->new_password])->save();
        $this->reset(['current_password', 'new_password', 'new_password_confirmation']);
        session()->flash('success_message', 'Şifreniz güncellendi.');
    }

    public function uploadDocument(KycService $kyc): void
    {
        $this->validate([
            'upload_type' => ['required', Rule::in(array_keys($this->allowedTypes()))],
            'upload_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'upload_file.mimes' => 'Belge JPG, PNG veya PDF olmalıdır.',
            'upload_file.max' => 'Belge en fazla 10 MB olabilir.',
        ]);

        try {
            $kyc->upload(Auth::user(), 'driver', $this->upload_type, $this->upload_file);
        } catch (\RuntimeException $e) {
            $this->addError('upload_file', $e->getMessage());

            return;
        }

        $this->reset(['upload_file']);
        session()->flash('success_message', 'Belge yüklendi ve inceleme kuyruğuna alındı.');
    }

    public function deleteAccount(AccountService $accounts): void
    {
        $this->validate([
            'delete_password' => 'required|current_password',
            'delete_reason' => 'nullable|string|max:500',
        ], ['delete_password.current_password' => 'Şifreniz hatalı.']);

        try {
            $accounts->deleteAccount(Auth::user(), $this->delete_reason);
        } catch (\RuntimeException $e) {
            $this->addError('delete_password', $e->getMessage());

            return;
        }

        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        $this->redirect(route('home'), navigate: false);
    }

    public function allowedTypes(): array
    {
        return app(KycService::class)->allowedTypes(Auth::user(), 'driver');
    }

    public function with(): array
    {
        $user = Auth::user();
        $profile = $user->driverProfile;
        $kyc = app(KycService::class);

        return [
            'profile' => $profile,
            'documents' => KycDocument::query()->where('user_id', $user->id)->latest()->get()->keyBy('document_type'),
            'allowedTypes' => $this->allowedTypes(),
            'requiredTypes' => $kyc->requiredTypes($user, 'driver'),
            'missingTypes' => $kyc->missingTypes($user, 'driver'),
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Profil, Belgeler ve Güvenlik</h2>
        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">İletişim bilgileriniz, sürücü belgeleriniz, bildirim tercihleri ve hesap güvenliği.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <div class="lg:col-span-2 space-y-6">

            <form wire:submit.prevent="updateProfile" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Hesap bilgileri</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Ad</label>
                        <input type="text" wire:model="first_name" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('first_name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Soyad</label>
                        <input type="text" wire:model="last_name" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('last_name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">E-posta adresi</label>
                        <input type="email" wire:model="email" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('email') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Cep telefonu</label>
                        <input type="text" wire:model="phone" inputmode="tel" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white font-mono focus:border-brand-500 focus:outline-none">
                        @error('phone') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <p class="text-[11px] text-neutral-500">E-posta adresinizi değiştirirseniz bir sonraki girişte yeni adresinize doğrulama kodu gönderilir.</p>

                <div class="pt-2 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all">
                        <span wire:loading.remove wire:target="updateProfile">Değişiklikleri kaydet</span>
                        <span wire:loading wire:target="updateProfile">Kaydediliyor...</span>
                    </button>
                </div>
            </form>

            <form wire:submit.prevent="updatePassword" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Şifre değiştir</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Mevcut şifre</label>
                        <input type="password" wire:model="current_password" autocomplete="current-password" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('current_password') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Yeni şifre (en az 12 karakter)</label>
                        <input type="password" wire:model="new_password" autocomplete="new-password" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('new_password') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Yeni şifre (tekrar)</label>
                        <input type="password" wire:model="new_password_confirmation" autocomplete="new-password" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                    </div>
                </div>
                <div class="pt-2 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 border border-neutral-300 dark:border-neutral-700 text-neutral-900 dark:text-white font-bold text-xs transition-all">Şifreyi güncelle</button>
                </div>
            </form>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Sürücü ve araç belgeleri</h3>
                    @php $kycStatus = $profile?->kyc_status ?? 'unsubmitted'; @endphp
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border
                        {{ $kycStatus === 'approved' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : ($kycStatus === 'pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-400' : ($kycStatus === 'rejected' ? 'bg-rose-500/10 border-rose-500/20 text-rose-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300')) }}">
                        {{ ['approved' => 'Doğrulandı', 'pending' => 'İnceleniyor', 'rejected' => 'Belge reddedildi', 'unsubmitted' => 'Belge bekleniyor'][$kycStatus] ?? $kycStatus }}
                    </span>
                </div>

                @if($profile?->kyc_notes)
                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs">{{ $profile->kyc_notes }}</div>
                @endif

                <div class="space-y-2 text-xs">
                    @foreach($allowedTypes as $type => $label)
                        @php $doc = $documents[$type] ?? null; @endphp
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div>
                                <div class="text-neutral-900 dark:text-white font-semibold">{{ $label }} @if(in_array($type, $requiredTypes, true))<span class="text-brand-500">*</span>@endif</div>
                                <div class="text-[11px] text-neutral-500">
                                    @if($doc)
                                        Yüklendi: {{ $doc->created_at->format('d.m.Y H:i') }}
                                        @if($doc->status === 'rejected' && $doc->review_notes) · {{ $doc->review_notes }} @endif
                                    @else
                                        Henüz yüklenmedi
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                @if($doc)
                                    <a href="{{ route('files.kyc', $doc->id) }}" target="_blank" rel="noopener" class="text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white underline">Görüntüle</a>
                                    <span class="font-bold {{ $doc->status === 'approved' ? 'text-emerald-400' : ($doc->status === 'rejected' ? 'text-rose-400' : 'text-amber-400') }}">
                                        {{ \App\Models\KycDocument::STATUS_LABELS[$doc->status] ?? $doc->status }}
                                    </span>
                                @else
                                    <span class="text-neutral-500">Bekleniyor</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($kycStatus !== 'approved')
                    <form wire:submit.prevent="uploadDocument" class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs pt-2 border-t border-neutral-200 dark:border-neutral-800">
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Belge türü</label>
                            <select wire:model="upload_type" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                                @foreach($allowedTypes as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Dosya (JPG, PNG, PDF)</label>
                            <input type="file" wire:model="upload_file" accept="image/jpeg,image/png,application/pdf" class="w-full text-neutral-500 dark:text-neutral-400 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-900 dark:file:text-white">
                            @error('upload_file') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                            <div wire:loading wire:target="upload_file" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="uploadDocument">Belgeyi yükle</span>
                                <span wire:loading wire:target="uploadDocument">Yükleniyor...</span>
                            </button>
                        </div>
                    </form>
                    @if(count($missingTypes))
                        <p class="text-[11px] text-neutral-500">Eksik zorunlu belgeler yüklendiğinde profiliniz incelemeye alınır.</p>
                    @endif
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <form wire:submit.prevent="updatePreferences" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Bildirim ve rota tercihleri</h3>
                <div class="space-y-3 text-xs">
                    <label class="flex items-start justify-between gap-3 cursor-pointer">
                        <span>
                            <span class="block font-bold text-neutral-900 dark:text-white">Yeni ilan e-postaları</span>
                            <span class="text-neutral-500 dark:text-neutral-400">Tercih ettiğiniz rotalarda yeni ilan açıldığında e-posta alın.</span>
                        </span>
                        <input type="checkbox" wire:model="notify_new_loads" class="mt-1 w-4 h-4 rounded bg-neutral-50 dark:bg-neutral-950 border-neutral-300 dark:border-neutral-700 text-brand-500">
                    </label>
                    <label class="flex items-start justify-between gap-3 cursor-pointer">
                        <span>
                            <span class="block font-bold text-neutral-900 dark:text-white">Teklif sonuçları</span>
                            <span class="text-neutral-500 dark:text-neutral-400">Teklifiniz kabul veya reddedildiğinde e-posta alın.</span>
                        </span>
                        <input type="checkbox" wire:model="notify_offer_results" class="mt-1 w-4 h-4 rounded bg-neutral-50 dark:bg-neutral-950 border-neutral-300 dark:border-neutral-700 text-brand-500">
                    </label>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tercih ettiğim rotalar</label>
                        <input type="text" wire:model="preferred_routes" placeholder="Örn. Ankara - İzmir, İstanbul - Bursa" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('preferred_routes') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex items-center justify-between pt-2 text-xs">
                    <span class="text-neutral-500">Premium: <span class="font-bold {{ $profile?->isPremium() ? 'text-emerald-400' : 'text-neutral-700 dark:text-neutral-300' }}">{{ $profile?->isPremium() ? $profile->premium_until->format('d.m.Y').' tarihine kadar' : 'Pasif' }}</span></span>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">Kaydet</button>
                </div>
            </form>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs">
                <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Araçlarım</h3>
                <p class="text-neutral-500 dark:text-neutral-400">Araç ekleme, ruhsat yükleme ve aktif araç seçimi araçlar sayfasından yapılır.</p>
                <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">Araçları yönet →</a>
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-rose-900/40 rounded-2xl p-6 space-y-3">
                <h3 class="text-xs font-bold text-rose-400 uppercase tracking-wider">Hesabı kapat</h3>
                <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">Devam eden ilan, sevkiyat veya ödenmemiş hakediş yoksa hesabınız kapatılır ve kişisel verileriniz anonimleştirilir. Bu işlem geri alınamaz.</p>
                <button type="button" wire:click="$set('deleteModalOpen', true)" class="w-full px-4 py-2.5 rounded-xl border border-rose-500/40 text-rose-300 hover:bg-rose-500/10 text-xs font-bold">Hesabımı kapatmak istiyorum</button>
            </div>
        </div>
    </div>

    @if($deleteModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('deleteModalOpen', false)"></div>
            <form wire:submit.prevent="deleteAccount" class="relative z-10 w-full max-w-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Hesabı kalıcı olarak kapat</h3>
                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">Onaylamak için şifrenizi girin. İsterseniz kapatma nedeninizi de yazabilirsiniz.</p>
                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Şifre</label>
                    <input type="password" wire:model="delete_password" autocomplete="current-password" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-rose-500 focus:outline-none">
                    @error('delete_password') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Neden (isteğe bağlı)</label>
                    <textarea wire:model="delete_reason" rows="2" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-rose-500 focus:outline-none"></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('deleteModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 font-semibold">Vazgeç</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold" wire:loading.attr="disabled">Hesabı kapat</button>
                </div>
            </form>
        </div>
    @endif
</div>
