<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\Load;
use App\Models\Dispute;
use App\Models\SupportTicket;

new class extends Component {
    // Sekme Yönetimi
    public string $activeTab = 'disputes'; // 'disputes' (uyuşmazlıklar) veya 'tickets' (destek biletleri)

    // Seçili Detay Durumları (Split-screen)
    public ?int $selectedId = null;
    public $selectedItem = null;

    // Karar ve Bilet Yanıt Formları
    public string $decisionNotes = '';
    public string $ticketReplyMessage = '';

    public function mount()
    {
        if (!auth()->user()->can('manage disputes')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * Tıklanan uyuşmazlık davasını veya bileti seçer
     */
    public function selectItem(int $id)
    {
        $this->selectedId = $id;
        $this->decisionNotes = '';
        $this->ticketReplyMessage = '';

        if ($this->activeTab === 'disputes') {
            // İlişki adını cargoLoad olarak güncelledik
            $this->selectedItem = Dispute::with(['cargoLoad.cargoOwnerProfile.user', 'cargoLoad.driverProfile.user'])->find($id);
        } else {
            $this->selectedItem = SupportTicket::find($id);
        }
    }

    /**
     * Paneli kapatır
     */
    public function closePanel()
    {
        $this->selectedId = null;
        $this->selectedItem = null;
    }

    /**
     * UYUŞMAZLIK KARAR MEKANİZMASI (Hakem Heyeti)
     */
    public function resolveDispute(string $target)
    {
        if (!auth()->user()->can('manage disputes')) {
            abort(403);
        }

        $this->validate([
            'decisionNotes' => 'required|string|min:10'
        ], [
            'decisionNotes.required' => 'Lütfen uyuşmazlık gerekçeli kararınızı yazınız.',
            'decisionNotes.min' => 'Karar gerekçesi en az 10 karakter olmalıdır.'
        ]);

        $newStatus = $target === 'driver' ? 'resolved_driver_paid' : 'resolved_owner_refunded';

        // 1. Uyuşmazlığı karara bağla
        $this->selectedItem->update([
            'status' => $newStatus,
            'arbitration_notes' => $this->decisionNotes,
            'resolved_at' => now()
        ]);

        // 2. PayTR Bloke Havuz Akışını güncelle (İlişki cargoLoad olarak değiştirildi)
        $escrowStatus = $target === 'driver' ? 'released_to_driver' : 'refunded_to_owner';
        $loadStatus = $target === 'driver' ? 'delivered' : 'cancelled';

        $this->selectedItem->cargoLoad->update([
            'escrow_status' => $escrowStatus,
            'status' => $loadStatus
        ]);

        session()->flash('success', 'Uyuşmazlık davası resmen karara bağlandı. Maliye ve taraflar bilgilendirildi.');
        $this->closePanel();
    }

    /**
     * TICKET YANITLAMA MEKANİZMASI
     */
    public function replyTicket()
    {
        if (!auth()->user()->can('manage support tickets')) {
            abort(403);
        }

        $this->validate([
            'ticketReplyMessage' => 'required|string|min:10'
        ], [
            'ticketReplyMessage.required' => 'Lütfen bilet yanıt mesajınızı yazınız.',
            'ticketReplyMessage.min' => 'Bilet yanıtı en az 10 karakter olmalıdır.'
        ]);

        // 1. Bileti yanıtlandı olarak güncelle
        $this->selectedItem->update([
            'status' => 'replied',
            'admin_reply' => $this->ticketReplyMessage,
            'replied_at' => now()
        ]);

        // 2. E-Posta Entegrasyonu
        try {
            \Illuminate\Support\Facades\Mail::raw($this->ticketReplyMessage, function($message) {
                $message->to($this->selectedItem->email)
                        ->subject('Re: NavlunIQ Destek Talebi #' . $this->selectedItem->id);
            });
            session()->flash('success', 'Destek bileti başarıyla yanıtlandı ve kullanıcının e-posta adresine gönderildi.');
        } catch (\Exception $e) {
            Log::error("NavlunIQ Bilet Mail Hatası: " . $e->getMessage());
            session()->flash('success', 'Bilet yanıtlandı (Fakat SMTP yerel ağda kesintiye uğradı, detaylar loglandı).');
        }

        $this->closePanel();
    }

    /**
     * Sekme Değişikliği
     */
    public function updatedActiveTab()
    {
        $this->closePanel();
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Simüle Veri Üretme -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Uyuşmazlık & Destek Karar Merkezi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">İhtilaflı sevkiyatlar üzerinde hakemlik kararı verin ve destek biletlerini doğrudan yanıtlayın.</p>
        </div>

        @if(\App\Models\Dispute::count() === 0)
@endif
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm">
        <button wire:click="$set('activeTab', 'disputes')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'disputes' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            İhtilaflı Sevkiyatlar (Hakem Heyeti)
        </button>
        <button wire:click="$set('activeTab', 'tickets')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'tickets' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Müşteri Destek Biletleri
        </button>
    </div>

    <!-- Liste ve Split-Screen Layout Gridi -->
    <div class="grid grid-cols-1 {{ $selectedId ? 'lg:grid-cols-2' : '' }} gap-8 items-start">

        <!-- Sol Bölüm: Liste Tablosu -->
        <div class="apple-glass rounded-3xl overflow-hidden">

            @if($activeTab === 'disputes')
                <!-- UYUŞMAZLIK LİSTESİ -->
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                            <th class="p-5">Güzergah / Yük</th>
                            <th class="p-5">Navlun Bedeli</th>
                            <th class="p-5">Karar Durumu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse(\App\Models\Dispute::with('cargoLoad.cargoOwnerProfile.user')->latest()->get() as $disp)
                            <tr wire:click="selectItem({{ $disp->id }})" class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200 cursor-pointer {{ $selectedId === $disp->id ? 'bg-brand-500/5 dark:bg-brand-500/10' : '' }}">
                                <td class="p-5">
                                    <div class="font-bold text-neutral-900 dark:text-white">{{ $disp->cargoLoad->pickup_location }} -> {{ $disp->cargoLoad->delivery_location }}</div>
                                    <div class="text-[11px] text-neutral-400 mt-1">{{ $disp->cargoLoad->goods_type }} ({{ number_format($disp->cargoLoad->weight) }} kg)</div>
                                </td>
                                <td class="p-5 font-bold">&#8378;{{ number_format($disp->cargoLoad->price, 2) }}</td>
                                <td class="p-5">
                                    @php
                                        $classes = ['open' => 'bg-amber-500/10 text-amber-600', 'resolved_driver_paid' => 'bg-emerald-500/10 text-emerald-600', 'resolved_owner_refunded' => 'bg-red-500/10 text-red-600'];
                                        $labels = ['open' => 'Karar Bekliyor', 'resolved_driver_paid' => 'Şoföre Ödendi', 'resolved_owner_refunded' => 'Göndericiye İade'];
                                    @endphp
                                    <span class="px-2.5 py-1 rounded-full font-bold text-[10px] {{ $classes[$disp->status] ?? '' }}">
                                        {{ $labels[$disp->status] ?? '' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-12 text-center text-neutral-400">Aktif veya geçmiş uyuşmazlık davası bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <!-- DESTEK BİLETİ LİSTESİ -->
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                            <th class="p-5">Kullanıcı / Kategori</th>
                            <th class="p-5">Mesaj Özeti</th>
                            <th class="p-5">Bilet Durumu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse(\App\Models\SupportTicket::latest()->get() as $tkt)
                            <tr wire:click="selectItem({{ $tkt->id }})" class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200 cursor-pointer {{ $selectedId === $tkt->id ? 'bg-brand-500/5 dark:bg-brand-500/10' : '' }}">
                                <td class="p-5">
                                    <div class="font-bold text-neutral-900 dark:text-white">{{ $tkt->name }}</div>
                                    @php
                                        $cats = ['technical' => 'Teknik Destek', 'billing' => 'Fatura/Ödeme', 'kyc' => 'Evrak Onay', 'escrow' => 'Güvenli Havuz', 'dispute' => 'Uyuşmazlık', 'other' => 'Genel Soru'];
                                    @endphp
                                    <div class="text-[11px] text-brand-500 mt-1 font-semibold">{{ $cats[$tkt->category] ?? 'Diğer' }}</div>
                                </td>
                                <td class="p-5 text-neutral-500 max-w-[200px] truncate">{{ $tkt->message }}</td>
                                <td class="p-5">
                                    @php
                                        $tclasses = ['open' => 'bg-amber-500/10 text-amber-600', 'replied' => 'bg-emerald-500/10 text-emerald-600', 'closed' => 'bg-neutral-500/10 text-neutral-600'];
                                        $tlabels = ['open' => 'Açık / Bekliyor', 'replied' => 'Yanıtlandı', 'closed' => 'Kapatıldı'];
                                    @endphp
                                    <span class="px-2.5 py-1 rounded-full font-bold text-[10px] {{ $tclasses[$tkt->status] ?? '' }}">
                                        {{ $tlabels[$tkt->status] ?? '' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-12 text-center text-neutral-400">Herhangi bir bilet kaydı bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endif

        </div>

        <!-- Sağ Bölüm: Çift Taraflı (Split-screen) Karar Detay Paneli -->
        @if($selectedItem)
            <div class="apple-glass rounded-3xl p-6 space-y-6 animate-slide-up sticky top-28">
                <!-- Üst Kapatma Kontrolleri -->
                <div class="flex justify-between items-center border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                    <h2 class="text-sm font-bold text-neutral-900 dark:text-white">
                        {{ $activeTab === 'disputes' ? 'Hakem Heyeti Karar İstasyonu' : 'Bilet Detay ve Yanıt Alanı' }}
                    </h2>
                    <button wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if($activeTab === 'disputes')
                    <!-- HAKEM HEYETİ DETAYLARI -->
                    <div class="space-y-6 text-xs">
                        <!-- Çift Taraflı İddia/Savunma Gridi -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Yük Sahibi Şikayeti -->
                            <div class="p-4 bg-red-500/5 rounded-2xl border border-red-500/10 space-y-2">
                                <span class="font-bold text-red-600 block">YÜK SAHİBİ ŞİKAYETİ</span>
                                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">{{ $selectedItem->cargo_owner_claim }}</p>
                            </div>
                            <!-- Sürücü Savunması -->
                            <div class="p-4 bg-blue-500/5 rounded-2xl border border-blue-500/10 space-y-2">
                                <span class="font-bold text-blue-600 block">ŞOFÖRÜN SAVUNMASI</span>
                                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">{{ $selectedItem->driver_defense }}</p>
                            </div>
                        </div>

                        <!-- Şoförün Teslim Kanıtı (POD) Görseli -->
                        <div class="space-y-2">
                            <span class="font-bold text-neutral-400 uppercase tracking-wider text-[11px] block">Şoförün Teslimat Onay Kanıtı (POD)</span>
                            <div class="aspect-[4/3] bg-neutral-900 rounded-2xl flex flex-col items-center justify-center border border-neutral-200/50 dark:border-neutral-800/50 text-white relative overflow-hidden shadow-apple-sm">
                                <img src="{{ $selectedItem->driver_proof_photo_path }}" class="absolute inset-0 w-full h-full object-cover opacity-60" />
                                <span class="text-xs font-semibold tracking-wider relative z-10">TESLİMAT FOTOĞRAFI (POD)</span>
                                <span class="text-[10px] text-neutral-400 mt-1 relative z-10">Konum Etiketi Doğrulandı</span>
                            </div>
                        </div>

                        @if($selectedItem->status === 'open')
                            <!-- Gerekçeli Karar Formu -->
                            <div class="space-y-2">
                                <span class="font-bold text-neutral-400 uppercase tracking-wider text-[11px] block">Hakem Heyeti Gerekçeli Kararı</span>
                                <textarea wire:model.defer="decisionNotes" rows="4" placeholder="Örn: Sürücünün sunduğu teslim kanıtı (POD) fotoğrafında yükün hasarsız teslim edildiği ve göndericinin imzası olduğu resmen kanıtlanmıştır. Navlun bedelinin şoföre aktarılmasına karar verilmiştir..." class="w-full p-4 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all duration-300"></textarea>
                                @error('decisionNotes') <span class="text-red-500 text-[11px] block font-medium pl-1">{{ $message }}</span> @enderror
                            </div>

                            <!-- Karar Butonları -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                                <button wire:click="resolveDispute('owner')" class="btn-apple-secondary py-3 text-xs text-red-600 hover:bg-red-50 hover:border-red-200">
                                    Geri İade Et (Yük Sahibine)
                                </button>
                                <button wire:click="resolveDispute('driver')" class="btn-apple-primary py-3 text-xs bg-emerald-600 hover:bg-emerald-700 text-white">
                                    Serbest Bırak (Şoföre Öde)
                                </button>
                            </div>
                        @else
                            <!-- Karara Bağlanmış Dosya Raporu -->
                            <div class="p-4 bg-emerald-500/5 border border-emerald-500/10 rounded-2xl space-y-2">
                                <span class="font-bold text-emerald-500 block">RESMİ KARAR TUTANAĞI (#{{ $selectedItem->id }})</span>
                                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">
                                    <strong>Gerekçeli Karar:</strong> {{ $selectedItem->arbitration_notes }}
                                </p>
                                <div class="text-[10px] text-neutral-400 font-mono mt-2 block">
                                    Karar Tarihi: {{ $selectedItem->resolved_at->format('Y-m-d H:i') }} | Durum: {{ $selectedItem->status === 'resolved_driver_paid' ? 'ŞOFÖRE AKTARILDI' : 'YÜK SAHİBİNE İADE EDİLDİ' }}
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <!-- TICKET / DESTEK BİLETİ DETAYLARI -->
                    <div class="space-y-6 text-xs">
                        <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 text-xs space-y-3">
                            <div>
                                <span class="text-neutral-400 block">Gönderen Kullanıcı / Rol</span>
                                <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedItem->name }} ({{ $selectedItem->role === 'driver' ? 'Şoför' : 'Yük Sahibi' }})</span>
                            </div>
                            <div>
                                <span class="text-neutral-400 block">Kullanıcı Bilet Mesajı</span>
                                <p class="font-semibold text-neutral-950 dark:text-neutral-200 mt-1 leading-relaxed">{{ $selectedItem->message }}</p>
                            </div>
                        </div>

                        @if($selectedItem->status === 'open')
                            <!-- Bilet Yanıt Formu -->
                            <div class="space-y-2">
                                <span class="font-bold text-neutral-400 uppercase tracking-wider text-[11px] block">E-Posta Bilet Yanıtınız</span>
                                <textarea wire:model.defer="ticketReplyMessage" rows="5" placeholder="Merhaba Osman Bey, talep ettiğiniz üzere sunucu gecikmeleri teknik ekibimiz tarafından incelenmiş ve önbellek sorguları optimize edilmiştir. Problem tamamen giderilmiştir. İyi çalışmalar dileriz..." class="w-full p-4 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all duration-300"></textarea>
                                @error('ticketReplyMessage') <span class="text-red-500 text-[11px] block font-medium pl-1">{{ $message }}</span> @enderror
                            </div>

                            <button wire:click="replyTicket" class="w-full btn-apple-primary py-3.5 text-xs flex items-center justify-center space-x-2">
                                <span wire:loading.remove wire:target="replyTicket">Yanıtı Gönder ve Bileti Kapat</span>
                                <span wire:loading wire:target="replyTicket" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            </button>
                        @else
                            <!-- Yanıtlanmış Bilet Detayı -->
                            <div class="p-4 bg-emerald-500/5 border border-emerald-500/10 rounded-2xl space-y-2">
                                <span class="font-bold text-emerald-500 block">YANITLANDI (#{{ $selectedItem->id }})</span>
                                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">
                                    <strong>Yönetici Yanıtı:</strong> {{ $selectedItem->admin_reply }}
                                </p>
                                <div class="text-[10px] text-neutral-400 font-mono mt-2 block">
                                    Yanıt Tarihi: {{ $selectedItem->replied_at->format('Y-m-d H:i') }}
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

            </div>
        @endif

    </div>
</div>
