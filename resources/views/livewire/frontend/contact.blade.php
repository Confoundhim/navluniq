<?php

use Livewire\Volt\Component;
use App\Models\SupportTicket;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = 'guest';
    public string $category = 'other';
    public string $message = '';

    public function submitTicket()
    {
        $this->validate([
            'name' => 'required|string|min:3',
            'email' => 'required|email',
            'phone' => 'required|string|min:10',
            'message' => 'required|string|min:15'
        ], [
            'name.required' => 'Ad Soyad alanı boş bırakılamaz.',
            'email.required' => 'E-posta adresi boş bırakılamaz.',
            'phone.required' => 'Telefon numarası boş bırakılamaz.',
            'message.required' => 'Lütfen mesajınızı yazınız.',
            'message.min' => 'Mesajınız en az 15 karakter olmalıdır.'
        ]);

        SupportTicket::create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'category' => $this->category,
            'message' => $this->message,
            'status' => 'open'
        ]);

        $this->reset(['name', 'email', 'phone', 'message']);
        session()->flash('success', 'Destek talebiniz başarıyla alındı! Ekibimiz en kısa sürede e-posta adresinize yanıt iletecektir.');
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

        <!-- Sol: 12 Konu Kategorili Bilet Formu -->
        <div class="lg:col-span-2 apple-glass rounded-3xl p-8 md:p-10 space-y-6 shadow-apple-md">
            <h3 class="text-sm font-bold text-neutral-900 dark:text-white uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800">BİZİMLE İLETİŞİME GEÇİN</h3>

            <form wire:submit.prevent="submitTicket" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Adınız Soyadınız</label>
                        <input type="text" wire:model.defer="name" placeholder="Ad Soyad" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('name') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Telefon Numaranız</label>
                        <input type="text" wire:model.defer="phone" placeholder="05XXXXXXXXX" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('phone') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">E-Posta Adresiniz</label>
                        <input type="email" wire:model.defer="email" placeholder="ornek@mail.com" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white">
                        @error('email') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Platform Rolünüz</label>
                        <select wire:model.defer="role" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white font-semibold">
                            <option value="cargo_owner">Yük Sahibi (Gönderici)</option>
                            <option value="driver">Şoför (Taşıyıcı)</option>
                            <option value="guest">Ziyaretçi / Misafir</option>
                        </select>
                    </div>
                </div>

                <!-- 12 Konu Kategorisi Seçici -->
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Konu Kategorisi</label>
                    <select wire:model.defer="category" class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white font-semibold">
                        <option value="technical">Teknik Hata, Bug ve Çökme</option>
                        <option value="billing">Abonelik & Fiyatlandırma</option>
                        <option value="kyc">Evrak Analizi (KYC)</option>
                        <option value="escrow">PayTR Ödeme & Escrow</option>
                        <option value="other">İlan & Rota Sorunları</option>
                        <option value="technical">PWA & GPS Sinyal Hataları</option>
                        <option value="technical">WhatsApp Entegrasyonları / Telegram</option>
                        <option value="dispute">Uyuşmazlık (Dispute) Yönetimi</option>
                        <option value="billing">Fatura & Muhasebe</option>
                        <option value="technical">Hesap Güvenliği</option>
                        <option value="other">Diğer / Genel Sorular</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Mesajınız</label>
                    <textarea wire:model.defer="message" rows="5" placeholder="Talep, soru veya sorununuzu detaylı olarak yazınız..." class="w-full p-4 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none text-neutral-900 dark:text-white"></textarea>
                    @error('message') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
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
                        <span class="font-bold text-neutral-900 dark:text-white">+90 850 304 04 00</span>
                    </div>
                    <div>
                        <span class="text-neutral-400 block text-[10px]">E-Posta Adresimiz</span>
                        <span class="font-bold text-neutral-900 dark:text-white">info@navluniq.com</span>
                    </div>
                    <div>
                        <span class="text-neutral-400 block text-[10px]">Merkez Adresimiz</span>
                        <span class="font-bold text-neutral-900 dark:text-white">Cevizlidere Mah. Mevlana Blv. No: 221 /109 Çankaya, Ankara</span>
                    </div>
                </div>
            </div>

            <!-- Google Maps Entegrasyonu -->
            <div class="apple-glass rounded-3xl overflow-hidden border border-neutral-200/50 shadow-apple-md h-64 relative">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3061.6517120578505!2d32.81019417650525!3d39.882038788008295!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x14d345e0df8e262b%3A0x62c27832d6a96e13!2sCevizlidere%2C%20Mevlana%20Blv.%20No%3A221%2C%2006520%20%C3%87ankaya%2FAnkara!5e0!3m2!1str!2str!4v1788433898346!5m2!1str!2str"
                        class="w-full h-full border-0"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>

    </div>
</div>
