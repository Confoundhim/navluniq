<?php

use App\Models\SupportTicket;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $role = 'guest';

    public string $category = 'other';

    public string $message = '';

    public function mount(): void
    {
        if ($user = Auth::user()) {
            $this->name = $user->full_name;
            $this->email = $user->email;
            $this->phone = Phone::format($user->phone);
            $this->role = in_array($user->current_role, ['cargo_owner', 'driver'], true) ? $user->current_role : 'guest';
        }
    }

    public function submitTicket(): void
    {
        $this->validate([
            'name' => 'required|string|min:3|max:120',
            'email' => 'required|email|max:255',
            'phone' => ['required', 'string', Phone::RULE],
            'role' => ['required', Rule::in(['cargo_owner', 'driver', 'guest'])],
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'message' => 'required|string|min:15|max:4000',
        ], [
            'name.required' => 'Ad Soyad alanı boş bırakılamaz.',
            'email.required' => 'E-posta adresi boş bırakılamaz.',
            'phone.required' => 'Telefon numarası boş bırakılamaz.',
            'phone.regex' => 'Geçerli bir cep telefonu numarası girin.',
            'message.required' => 'Lütfen mesajınızı yazınız.',
            'message.min' => 'Mesajınız en az 15 karakter olmalıdır.',
        ]);

        $key = 'contact-form:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('message', 'Kısa sürede çok fazla talep gönderildi. Lütfen birkaç dakika sonra tekrar deneyin.');

            return;
        }
        RateLimiter::hit($key, 600);

        SupportTicket::create([
            'user_id' => Auth::id(),
            'name' => trim($this->name),
            'email' => mb_strtolower(trim($this->email)),
            'phone' => Phone::normalize($this->phone),
            'role' => $this->role,
            'category' => $this->category,
            'subject' => SupportTicket::CATEGORIES[$this->category],
            'message' => trim($this->message),
            'status' => 'open',
        ]);

        $this->reset(['message']);
        if (! Auth::check()) {
            $this->reset(['name', 'email', 'phone']);
        }

        session()->flash('success', 'Destek talebiniz alındı. Ekibimiz en kısa sürede e-posta ile yanıt verecektir.');
    }
}; ?>


<div class="max-w-6xl mx-auto px-6 md:px-12 space-y-16 animate-fade-in text-xs">

    <div class="text-center space-y-4 max-w-3xl mx-auto">
        <span class="text-xs font-black text-brand-500 uppercase tracking-widest">BİZE ULAŞIN</span>
        <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
            Yolculuğunuzun Her Anında Yanınızdayız
        </h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
            Sorularınız, iş birliği teklifleriniz veya destek talepleriniz için ekibimiz kesintisiz hizmet vermektedir.
        </p>
    </div>

    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 items-start">

        
        <div class="lg:col-span-2 apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-md">
            <h3 class="text-sm font-bold text-neutral-900 dark:text-white uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800">BİZİMLE İLETİŞİME GEÇİN</h3>

            <form wire:submit.prevent="submitTicket" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="form-label">Adınız Soyadınız</label>
                        <input type="text" wire:model="name" placeholder="Ad Soyad" class="form-input">
                        @error('name') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1">
                        <label class="form-label">Telefon Numaranız</label>
                        <input type="text" wire:model="phone" placeholder="05XXXXXXXXX" class="form-input">
                        @error('phone') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="form-label">E-Posta Adresiniz</label>
                        <input type="email" wire:model="email" placeholder="ornek@mail.com" class="form-input">
                        @error('email') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1">
                        <label class="form-label">Platform Rolünüz</label>
                        <select wire:model="role" class="form-input">
                            <option value="cargo_owner">Yük Sahibi (Gönderici)</option>
                            <option value="driver">Şoför (Taşıyıcı)</option>
                            <option value="guest">Ziyaretçi / Misafir</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="form-label">Konu Kategorisi</label>
                    <select wire:model="category" class="form-input">
                        @foreach(\App\Models\SupportTicket::CATEGORIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="form-label">Mesajınız</label>
                    <textarea wire:model="message" rows="5" placeholder="Talep, soru veya sorununuzu detaylı olarak yazınız..." class="form-input"></textarea>
                    @error('message') <span class="form-error">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-bold shadow-apple-md">
                    Mesajı Gönder (Bilet Oluştur)
                </button>
            </form>
        </div>

        <!-- Sağ: İletişim Bilgileri ve Google Maps -->
        <div class="space-y-6">
            <div class="apple-glass rounded-3xl p-8 space-y-4 shadow-apple-md">
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800">İLETİŞİM KANALLARI</h3>
                <div class="space-y-3 text-neutral-600 dark:text-neutral-300">
                    <div>
                        <span class="text-neutral-400 block text-[10px]">Müşteri Hizmetleri / Telefon</span>
                        <span class="font-bold text-neutral-900 dark:text-white">{{ config('company.phone') ?: 'Yakında' }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-400 block text-[10px]">E-Posta Adresimiz</span>
                        <span class="font-bold text-neutral-900 dark:text-white">{{ config('company.email') ?: 'Yakında' }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-400 block text-[10px]">Merkez Adresimiz</span>
                        <span class="font-bold text-neutral-900 dark:text-white">{{ config('company.address') ?: 'Yakında' }}</span>
                    </div>
                </div>
            </div>

            <!-- Google Maps Entegrasyonu -->
        </div>

    </div>
</div>
