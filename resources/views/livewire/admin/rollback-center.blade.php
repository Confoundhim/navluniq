<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\Load;
use App\Models\DriverVehicle;
use App\Models\CmsContent;
use App\Models\SettingRevision;

new class extends Component {
    // Sekme Yönetimi
    public string $activeTab = 'trash'; // 'trash' (çöp kutusu / soft deletes) veya 'revisions' (zaman makinesi)
    public string $trashModelType = 'users'; // 'users', 'loads', 'vehicles'

    // Finansal Güvenlik Uyarısı Modalı Durumu
    public bool $showFinancialAlertModal = false;

    public function mount()
    {
        if (!auth()->user()->can('manage settings')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * SİLİNEN VERİYİ TEK TIKLA GERİ YÜKLE (Restore)
     */
    public function restoreRecord(string $type, int $id)
    {
        if ($type === 'users') {
            User::onlyTrashed()->find($id)?->restore();
        } elseif ($type === 'loads') {
            Load::onlyTrashed()->find($id)?->restore();
        } elseif ($type === 'vehicles') {
            DriverVehicle::onlyTrashed()->find($id)?->restore();
        }

        session()->flash('success', 'Silinen kayıt başarıyla kurtarıldı ve sisteme geri yüklendi (Restore)!');
    }

    /**
     * VERİYİ KALICI OLARAK SİL (Force Delete)
     */
    public function forceDeleteRecord(string $type, int $id)
    {
        if ($type === 'users') {
            User::onlyTrashed()->find($id)?->forceDelete();
        } elseif ($type === 'loads') {
            Load::onlyTrashed()->find($id)?->forceDelete();
        } elseif ($type === 'vehicles') {
            DriverVehicle::onlyTrashed()->find($id)?->forceDelete();
        }

        session()->flash('success', 'Kayıt veritabanından kalıcı olarak temizlendi.');
    }

    /**
     * ZAMAN MAKİNESİ (ROLLBACK): AYARI TEK TIKLA ESKİ HALİNE DÖNDÜR
     */
    public function rollbackSetting(int $revisionId)
    {
        $revision = SettingRevision::find($revisionId);

        if (!$revision) return;

        // 1. Ayarı eski orijinal değerine geri döndür
        CmsContent::updateOrCreate(['key' => $revision->key], ['value' => $revision->old_value]);

        // 2. Revizyonu işlem gördü olarak temizle veya güncelle
        $revision->delete();

        session()->flash('success', "'{$revision->setting_label}' ayarı saniyeler içinde eski orijinal haline döndürüldü (Rollback)!");
    }

    /**
     * FİNANSAL İŞLEMİ GERİ ALMA GÜVENLİK KALKANI
     * Finansal işlemler tek tıkla geri alınamaz; yasal iptal protokolü tetiklenir.
     */
    public function triggerFinancialSafetyAlert()
    {
        $this->showFinancialAlertModal = true;
    }

    /**
     * Silinen kayıtları türe göre getirir
     */
    private function getTrashRecords()
    {
        if ($this->trashModelType === 'users') {
            return User::onlyTrashed()->latest()->get();
        } elseif ($this->trashModelType === 'loads') {
            return Load::onlyTrashed()->latest()->get();
        } elseif ($this->trashModelType === 'vehicles') {
            return DriverVehicle::onlyTrashed()->latest()->get();
        }
        return collect();
    }

    /**
     * Ayar revizyonlarını getirir
     */
    private function getRevisions()
    {
        return SettingRevision::with('user')->latest()->get();
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

    <!-- Üst Başlık ve Simüle Veri Üretme -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sistem Geri Yükleme ve Zaman Makinesi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Silinen verileri kurtarın, personellerin yaptığı hatalı metin değişikliklerini tek tıkla geri alın (Rollback).</p>
        </div>

        @if(\App\Models\SettingRevision::count() === 0)
@endif
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm">
        <button wire:click="$set('activeTab', 'trash')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'trash' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Çöp Kutusu (Soft Deletes Kurtarma)
        </button>
        <button wire:click="$set('activeTab', 'revisions')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'revisions' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Zaman Makinesi (Rollback İstasyonu)
        </button>
    </div>

    @if($activeTab === 'trash')
        <!-- SEKME 1: GELİŞMİŞ ÇÖP KUTUSU (SOFT DELETES) -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-neutral-100 dark:border-neutral-800/50">
                <div>
                    <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">KURTARILABİLİR SİLİNMİŞ VERİLER</h3>
                    <p class="text-xs text-neutral-400 mt-0.5">Sistemden silinen hiçbir veri kaybolmaz, tek tıkla geri yüklenebilir.</p>
                </div>

                <!-- Silinen Veri Türü Seçici -->
                <div class="flex items-center space-x-2 text-xs">
                    <button wire:click="$set('trashModelType', 'users')" class="px-3 py-1.5 rounded-lg font-bold border transition-colors {{ $trashModelType === 'users' ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 border-neutral-900' : 'border-neutral-200/50 dark:border-neutral-700/50 text-neutral-500' }}">Silinen Kullanıcılar</button>
                    <button wire:click="$set('trashModelType', 'loads')" class="px-3 py-1.5 rounded-lg font-bold border transition-colors {{ $trashModelType === 'loads' ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 border-neutral-900' : 'border-neutral-200/50 dark:border-neutral-700/50 text-neutral-500' }}">Silinen İlanlar</button>
                    <button wire:click="$set('trashModelType', 'vehicles')" class="px-3 py-1.5 rounded-lg font-bold border transition-colors {{ $trashModelType === 'vehicles' ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 border-neutral-900' : 'border-neutral-200/50 dark:border-neutral-700/50 text-neutral-500' }}">Silinen Araçlar</button>
                </div>
            </div>

            <!-- Silinen Kayıtlar Tablosu -->
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                        <th class="pb-3">Silinen Kayıt Detayı</th>
                        <th class="pb-3">Silinme Tarihi</th>
                        <th class="pb-3 text-right">Kurtarma Aksiyonu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse($this->getTrashRecords() as $item)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="py-4 font-bold text-neutral-900 dark:text-white">
                                @if($trashModelType === 'users')
                                    <div>{{ $item->full_name }}</div>
                                    <div class="text-[11px] text-neutral-400 font-normal mt-0.5">{{ $item->email }}</div>
                                @elseif($trashModelType === 'loads')
                                    <div>{{ $item->pickup_location }} -> {{ $item->delivery_location }}</div>
                                    <div class="text-[11px] text-neutral-400 font-normal mt-0.5">{{ $item->goods_type }} (₺{{ number_format($item->price, 2) }})</div>
                                @elseif($trashModelType === 'vehicles')
                                    <div>{{ $item->plate }} - {{ $item->brand }} {{ $item->model }}</div>
                                    <div class="text-[11px] text-neutral-400 font-normal mt-0.5">{{ strtoupper($item->vehicle_type) }}</div>
                                @endif
                            </td>
                            <td class="py-4 text-neutral-400 font-mono text-[11px]">
                                {{ $item->deleted_at->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="py-4 text-right space-x-2">
                                <button wire:click="restoreRecord('{{ $trashModelType }}', {{ $item->id }})" class="btn-apple-primary py-1.5 px-3 text-[10px] bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                                    Tek Tıkla Geri Yükle (Restore)
                                </button>
                                <button wire:click="forceDeleteRecord('{{ $trashModelType }}', {{ $item->id }})" class="btn-apple-secondary py-1.5 px-3 text-[10px] text-red-600 hover:bg-red-50 hover:border-red-200 font-bold">
                                    Kalıcı Olarak Sil
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-12 text-center text-neutral-400">Çöp kutusunda bu türde silinmiş bir kayıt bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @elseif($activeTab === 'revisions')
        <!-- SEKME 2: ZAMAN MAKİNESİ (ROLLBACK) -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <div class="flex justify-between items-center pb-4 border-b border-neutral-100 dark:border-neutral-800/50">
                <div>
                    <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">ZAMAN MAKİNESİ (ROLLBACK İSTASYONU)</h3>
                    <p class="text-xs text-neutral-400 mt-0.5">Personellerin yaptığı hatalı metin veya ayar değişikliklerini tek tıkla eski haline döndürün.</p>
                </div>
            </div>

            <!-- Revizyon Geçmişi Tablosu -->
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                        <th class="p-3">Değiştirilen Ayar</th>
                        <th class="p-3">Eski Orijinal Değer</th>
                        <th class="p-3">Hatalı Yeni Değer</th>
                        <th class="p-3 text-right">Zaman Makinesi Aksiyonu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse($this->getRevisions() as $rev)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="p-3">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $rev->setting_label }}</div>
                                <div class="text-[10px] text-neutral-400 font-mono mt-0.5">Key: {{ $rev->key }}</div>
                            </td>
                            <td class="p-3 text-emerald-600 dark:text-emerald-400 bg-emerald-50/30 dark:bg-emerald-950/20 rounded-l-xl font-medium max-w-[200px] truncate">
                                {{ $rev->old_value }}
                            </td>
                            <td class="p-3 text-red-500 bg-red-50/30 dark:bg-red-950/20 rounded-r-xl font-medium max-w-[200px] truncate">
                                {{ $rev->new_value }}
                            </td>
                            <td class="p-3 text-right">
                                <button wire:click="rollbackSetting({{ $rev->id }})" class="btn-apple-brand py-1.5 px-3.5 text-[10px] font-bold shadow-apple-sm">
                                    ↺ Eski Haline Geri Al (Rollback)
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-12 text-center text-neutral-400">Sistem üzerinde geri alınmayı bekleyen bir ayar revizyonu bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Finansal Geri Alma Güvenlik Bilgilendirmesi -->
            <div class="p-4 bg-brand-500/5 rounded-2xl border border-brand-500/10 flex justify-between items-center text-xs">
                <div class="space-y-0.5">
                    <span class="font-bold text-brand-500 block">Finansal Geri Alma Sınırı (Yasal Kalkan)</span>
                    <p class="text-neutral-500 dark:text-neutral-400 text-[11px]">Güvenlik gereği PayTR havuz onayları ve hak ediş ödemeleri tek tıkla geri alınamaz.</p>
                </div>
                <button wire:click="triggerFinancialSafetyAlert" class="btn-apple-secondary text-[10px] py-1.5 px-3 font-semibold">
                    Yasal Bilgi Al
                </button>
            </div>
        </div>
    @endif

    <!-- FİNANSAL GÜVENLİK BİLGİLENDİRME MODALI -->
    @if($showFinancialAlertModal)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center overflow-y-auto p-4 bg-black/40 backdrop-blur-sm animate-fade-in">
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-3xl w-full max-w-md mx-4 border border-neutral-100 dark:border-neutral-700/50 shadow-apple-lg space-y-4 text-xs">
                <div class="flex justify-between items-center pb-2 border-b border-neutral-100 dark:border-neutral-700/50">
                    <h3 class="text-sm font-bold text-red-600 flex items-center space-x-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span>Finansal Geri Alma Sınırı</span>
                    </h3>
                    <button wire:click="$set('showFinancialAlertModal', false)" class="p-1 rounded-full hover:bg-neutral-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <p class="text-neutral-600 dark:text-neutral-300 leading-relaxed">
                    NavlunIQ yasal güvenlik standartları gereğince; PayTR havuzundan çözülerek şoförün banka hesabına aktarılmış bir EFT ödemesi veya kesilmiş bir e-arşiv fatura <strong>tek tıkla geri alınamaz (Rollback yapılamaz)</strong>.
                </p>
                <p class="text-neutral-500 dark:text-neutral-400 text-[11px] leading-relaxed">
                    Bu işlemi iptal etmek veya parayı geri çekmek istiyorsanız, lütfen <strong>Uyuşmazlık (Dispute) ve Destek Merkezi</strong> üzerinden yasal iptal/iade kararını tetikleyiniz.
                </p>

                <div class="flex justify-end pt-2">
                    <button wire:click="$set('showFinancialAlertModal', false)" class="btn-apple-primary py-2 px-4 text-xs font-semibold">
                        Anladım ve Kabul Ediyorum
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
