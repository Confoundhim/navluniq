<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

new
#[Layout('components.layouts.driver')]
#[Title('İlan Havuzu & Akıllı Teklif Yönetimi')]
class extends Component {
    public string $activeTab='all'; public string $searchRoute=''; public string $filterVehicle='';
    public bool $offerModalOpen=false; public ?int $selectedLoadId=null; public ?Load $selectedLoad=null;
    public string $offered_price=''; public string $estimated_eta='1 Gün 4 Saat'; public string $offer_note='';
    public bool $cancelRequestModalOpen=false; public ?int $selectedOfferIdForCancel=null; public string $cancellation_reason='';
    public array $myOffers=[];
    public function mount(): void { $this->loadMyOffers(); }
    public function setTab(string $tab): void { abort_unless(in_array($tab,['all','escrow','scraped','my_offers'],true),422); $this->activeTab=$tab; $this->loadMyOffers(); }
    public function openOfferModal(int $loadId): void
    {
        $this->selectedLoadId=$loadId; $this->selectedLoad=Load::query()->whereKey($loadId)->where('status','active_seeking')->where('visibility','public')->firstOrFail();
        $this->offered_price=(string)$this->selectedLoad->price; $this->offerModalOpen=true;
    }
    public function submitOffer(): void
    {
        $this->validate(['offered_price'=>'required|numeric|min:500','estimated_eta'=>'required|string|max:80','offer_note'=>'nullable|string|max:1000']);
        $profile=Auth::user()?->driverProfile; abort_unless($profile && $profile->kyc_status==='approved',403);
        DB::transaction(function() use($profile):void {
            $load=Load::query()->lockForUpdate()->whereKey($this->selectedLoadId)->where('status','active_seeking')->where('visibility','public')->firstOrFail();
            abort_if(Offer::query()->where('load_id',$load->id)->where('driver_profile_id',$profile->id)->whereIn('status',['pending','accepted'])->exists(),422,'Bu ilana zaten teklif verdiniz.');
            Offer::create(['load_id'=>$load->id,'driver_profile_id'=>$profile->id,'amount'=>$this->offered_price,'currency'=>'TRY','message'=>trim($this->estimated_eta.' | '.$this->offer_note),'status'=>'pending','expires_at'=>now()->addDays(2)]);
        },3);
        $this->offerModalOpen=false; $this->loadMyOffers(); session()->flash('success_message','Teklifiniz kaydedildi.');
    }
    public function isWithinOneHour(string $createdAt): bool { return Carbon::parse($createdAt)->diffInMinutes(now())<=60; }
    public function withdrawInstantly(int $offerId): void
    {
        $profile=Auth::user()?->driverProfile; abort_unless($profile,403);
        $offer=Offer::query()->whereKey($offerId)->where('driver_profile_id',$profile->id)->where('status','pending')->firstOrFail();
        abort_if($offer->created_at->lt(now()->subHour()),422,'Bir saatten eski teklifler için gerekçe gerekir.');
        $offer->update(['status'=>'withdrawn','responded_at'=>now()]); $this->loadMyOffers();
    }
    public function openCancelRequestModal(int $offerId): void { $this->selectedOfferIdForCancel=$offerId; $this->cancellation_reason=''; $this->cancelRequestModalOpen=true; }
    public function submitCancelRequest(): void
    {
        $this->validate(['cancellation_reason'=>'required|string|min:15|max:1000']); $profile=Auth::user()?->driverProfile; abort_unless($profile,403);
        $offer=Offer::query()->whereKey($this->selectedOfferIdForCancel)->where('driver_profile_id',$profile->id)->where('status','pending')->firstOrFail();
        $offer->update(['status'=>'cancel_requested','message'=>trim(($offer->message ? $offer->message."\n" : '').'İptal gerekçesi: '.$this->cancellation_reason)]);
        $this->cancelRequestModalOpen=false; $this->loadMyOffers();
    }
    private function loadMyOffers(): void
    {
        $profile=Auth::user()?->driverProfile; if(!$profile){$this->myOffers=[];return;}
        $this->myOffers=Offer::query()->with('cargoLoad')->where('driver_profile_id',$profile->id)->latest()->get()->map(fn(Offer $o)=>[
        'id'=>$o->id,'load_id'=>$o->load_id,'route'=>($o->cargoLoad?->pickup_location ?? '—').' → '.($o->cargoLoad?->delivery_location ?? '—'),'goods'=>$o->cargoLoad?->goods_type ?? '—',
        'vehicle'=>$o->cargoLoad?->vehicle_type ?? '—','target_price'=>(float)($o->cargoLoad?->price ?? 0),'my_price'=>(float)$o->amount,'status'=>$o->status,'created_at'=>$o->created_at->toDateTimeString(),'cancel_reason'=>$o->status==='cancel_requested'?$o->message:null])->all();
    }
    public function with(): array
    {
        $profile=Auth::user()?->driverProfile; $ids=$profile ? Offer::where('driver_profile_id',$profile->id)->pluck('load_id'):collect();
        $q=Load::query()->where('status','active_seeking')->where('visibility','public')->whereNotIn('id',$ids);
        if($this->searchRoute!=='')$q->where(fn($x)=>$x->where('pickup_location','like','%'.$this->searchRoute.'%')->orWhere('delivery_location','like','%'.$this->searchRoute.'%'));
        if($this->filterVehicle!=='')$q->where('vehicle_type',$this->filterVehicle);
        $external=ScrapedLoad::query()->where('status','parsed_success')->where('visibility','public')->where(fn($x)=>$x->whereNull('available_to_free_at')->orWhere('available_to_free_at','<=',now()))->latest()->take(50)->get()->map(fn($x)=>['id'=>$x->id,'origin'=>$x->pickup_location,'destination'=>$x->delivery_location,'goods'=>$x->goods_type,'vehicle'=>'—','weight'=>$x->weight?number_format($x->weight).' Kg':'—','price'=>(float)$x->price,'phone'=>$x->masked_phone,'raw_phone'=>'','source_channel'=>$x->scraper?->name ?? 'Dış kaynak','time_ago'=>$x->created_at->diffForHumans()])->all();
        return ['systemLoads'=>$q->latest()->get(),'scrapedLoads'=>$external];
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

    <!-- Üst Başlık & Sekmeler -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="setTab('all')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'all' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Tüm Yük İlanları
            </button>
            <button type="button" wire:click="setTab('escrow')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'escrow' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                🛡️ %100 PayTR Havuz Korumalı
            </button>
            <button type="button" wire:click="setTab('scraped')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'scraped' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                ⚡ Sarı Rozetli Onaylı Dış İlanlar
            </button>
            <button type="button" wire:click="setTab('my_offers')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'my_offers' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Verdiğim Teklifler ({{ count($myOffers) }})
            </button>
        </div>

        @if($activeTab !== 'my_offers')
            <div class="flex items-center gap-3">
                <input type="text" wire:model.live.debounce.300ms="searchRoute" placeholder="Şehir / İlçe Ara (Örn: Ankara)..." class="bg-neutral-900 border border-neutral-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-neutral-500 focus:border-brand-500 focus:outline-none w-48 sm:w-60">
            </div>
        @endif
    </div>

    <!-- SEKMELERE GÖRE İÇERİK -->

    <!-- 1. SEKME: SİSTEM İLANLARI (Teklif Verilenler Gizlenir) -->
    @if($activeTab === 'all' || $activeTab === 'escrow')
        <div class="space-y-4">
            @forelse($systemLoads as $load)
                <div class="bg-neutral-900 border border-neutral-800 hover:border-neutral-700/80 rounded-2xl p-5 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                    <div class="space-y-3 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-[11px] font-bold">
                                #NVL-{{ str_pad((string)$load->id, 5, '0', STR_PAD_LEFT) }}
                            </span>
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold uppercase">
                                ✓ PayTR Escrow Havuz Güvenceli
                            </span>
                            <span class="text-xs text-neutral-500">
                                {{ $load->created_at?->format('d.m.Y H:i') }}
                            </span>
                        </div>

                        <div class="flex items-center space-x-3 text-sm font-bold text-white">
                            <span>{{ $load->pickup_location }}</span>
                            <span class="text-brand-500">&rarr;</span>
                            <span>{{ $load->delivery_location }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-400">
                            <div><span class="text-neutral-500">Araç Tipi:</span> <span class="text-neutral-200 font-medium uppercase">{{ str_replace('_', ' ', $load->vehicle_type) }}</span></div>
                            <div><span class="text-neutral-500">Yük:</span> <span class="text-neutral-200 font-medium">{{ $load->goods_type }}</span></div>
                            <div><span class="text-neutral-500">Ağırlık:</span> <span class="text-neutral-200 font-medium">{{ number_format($load->weight) }} Kg</span></div>
                            @if($load->e_irsaliye_no)
                                <div><span class="text-neutral-500">e-İrsaliye:</span> <span class="text-neutral-300 font-mono">Hazır</span></div>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                        <div class="text-left lg:text-right">
                            <span class="text-[10px] text-neutral-500 uppercase tracking-wider block">Yük Sahibi Bütçesi</span>
                            <div class="text-2xl font-black text-white font-mono">
                                {{ number_format((float)$load->price, 2, ',', '.') }} <span class="text-brand-500 text-lg">₺</span>
                            </div>
                        </div>

                        <button type="button" wire:click="openOfferModal({{ $load->id }})" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-2 active:scale-95">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Teklif Ver</span>
                        </button>
                    </div>

                </div>
            @empty
                <div class="p-8 text-center text-xs text-neutral-500 bg-neutral-900 rounded-2xl border border-neutral-800">
                    Henüz teklif vermediğiniz yeni bir sistem ilanı bulunmuyor veya aramanıza uygun ilan yok.
                </div>
            @endforelse
        </div>
    @endif

    <!-- 2. SEKME: SARI ROZETLİ DIŞ İLANLAR -->
    @if($activeTab === 'all' || $activeTab === 'scraped')
        <div class="space-y-4 pt-4">
            <div class="flex items-center justify-between pb-2">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                    <h3 class="text-sm font-bold text-white">Onaylı Dış Kaynak İlanları</h3>
                </div>
                <span class="text-[11px] text-amber-400 font-semibold bg-amber-500/10 border border-amber-500/20 px-2.5 py-0.5 rounded-full">
                    👑 20 Dk Erken Erişim
                </span>
            </div>

            @foreach($scrapedLoads as $item)
                <div class="bg-neutral-900 border border-amber-500/30 hover:border-amber-500/60 rounded-2xl p-5 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div class="space-y-3 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/30 text-[10px] font-bold uppercase">
                                ⚡ {{ $item['source_channel'] }}
                            </span>
                            <span class="text-xs text-neutral-500 font-mono">{{ $item['time_ago'] }}</span>
                        </div>

                        <div class="flex items-center space-x-3 text-sm font-bold text-white">
                            <span>{{ $item['origin'] }}</span>
                            <span class="text-amber-400">&rarr;</span>
                            <span>{{ $item['destination'] }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-400">
                            <div><span class="text-neutral-500">Araç:</span> <span class="text-neutral-200 font-medium">{{ $item['vehicle'] }}</span></div>
                            <div><span class="text-neutral-500">Yük:</span> <span class="text-neutral-200 font-medium">{{ $item['goods'] }}</span></div>
                            <div><span class="text-neutral-500">Tonaj:</span> <span class="text-neutral-200 font-medium">{{ $item['weight'] }}</span></div>
                        </div>

                        <div class="p-2.5 bg-neutral-950 rounded-xl border border-neutral-800 text-[11px] text-neutral-400 flex items-center justify-between">
                            <span>İletişim: <b class="text-white font-mono">{{ $item['phone'] }}</b></span>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-3 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                        <div class="text-left lg:text-right">
                            <span class="text-[10px] text-neutral-500 uppercase tracking-wider block">Bütçe</span>
                            <div class="text-2xl font-black text-amber-400 font-mono">
                                {{ number_format($item['price'], 2, ',', '.') }} ₺
                            </div>
                        </div>

                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <a href="tel:{{ $item['raw_phone'] }}" class="p-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-white transition-colors" title="Şimdi Ara">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </a>
                            <a href="https://wa.me/9{{ $item['raw_phone'] }}?text={{ urlencode('Merhaba, NavlunIQ uzerinden ilaniniz icin ulasiyorum.') }}" target="_blank" class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs transition-all">
                                WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- 3. SEKME: VERDİĞİM TEKLİFLER & 1 SAAT KONTROLLÜ GERİ ÇEKME -->
    @if($activeTab === 'my_offers')
        <div class="space-y-4">
            @forelse($myOffers as $offer)
                @php
                    $isWithinHour = $this->isWithinOneHour($offer['created_at']);
                @endphp
                <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                    <div class="space-y-3 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($offer['status'] === 'pending')
                                <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-bold uppercase flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                    Yük Sahibi Onayı Bekleniyor
                                </span>
                            @elseif($offer['status'] === 'cancel_requested')
                                <span class="px-2.5 py-0.5 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[10px] font-bold uppercase">
                                    ⏳ İptal Talebiniz Yük Sahibine Sunuldu
                                </span>
                            @elseif($offer['status'] === 'accepted')
                                <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase">
                                    ✓ Teklif Kabul Edildi (Seferde)
                                </span>
                            @endif

                            <span class="text-xs text-neutral-500 font-mono">
                                Verilme Zamanı: {{ Carbon::parse($offer['created_at'])->format('d.m.Y H:i') }}
                            </span>
                        </div>

                        <div class="text-sm font-bold text-white">{{ $offer['route'] }}</div>
                        <div class="text-xs text-neutral-400">{{ $offer['goods'] }} • {{ $offer['vehicle'] }}</div>

                        @if($offer['cancel_reason'])
                            <div class="p-3 bg-neutral-950 rounded-xl border border-rose-500/20 text-xs text-rose-300">
                                <b>İptal Gerekçeniz:</b> "{{ $offer['cancel_reason'] }}"
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-3 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                        <div class="text-left lg:text-right">
                            <span class="text-[10px] text-neutral-500 uppercase block">Verdiğiniz Teklif</span>
                            <div class="text-2xl font-black text-brand-400 font-mono">
                                {{ number_format($offer['my_price'], 2, ',', '.') }} ₺
                            </div>
                        </div>

                        <!-- 1 Saat Kuralı Butonları -->
                        <div class="w-full sm:w-auto">
                            @if($offer['status'] === 'pending')
                                @if($isWithinHour)
                                    <!-- 1 Saat İçinde: Doğrudan Koşulsuz Geri Çek -->
                                    <button type="button" wire:click="withdrawInstantly({{ $offer['id'] }})" wire:confirm="Teklifinizi sebepsiz olarak geri çekmek istediğinize emin misiniz?" class="w-full px-4 py-2 rounded-xl bg-neutral-800 hover:bg-rose-500/10 text-neutral-300 hover:text-rose-400 text-xs font-bold border border-neutral-700 transition-colors flex items-center justify-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>Koşulsuz Geri Çek (1 Saat Dolmadı)</span>
                                    </button>
                                @else
                                    <!-- 1 Saat Sonrası: Gerekçeli İptal Talebi Aç -->
                                    <button type="button" wire:click="openCancelRequestModal({{ $offer['id'] }})" class="w-full px-4 py-2 rounded-xl bg-neutral-800 hover:bg-amber-500/10 text-amber-400 text-xs font-bold border border-neutral-700 transition-colors flex items-center justify-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                        <span>İptal Talebi İlet (Gerekçe Zorunlu)</span>
                                    </button>
                                @endif
                            @elseif($offer['status'] === 'accepted')
                                <a href="{{ route('driver.disputes.index') }}" class="w-full px-4 py-2 rounded-xl bg-neutral-800 text-neutral-400 hover:text-rose-400 text-xs font-semibold block text-center">
                                    Sorun mu Var? (Uyuşmazlık Aç)
                                </a>
                            @endif
                        </div>
                    </div>

                </div>
            @empty
                <div class="p-8 text-center text-xs text-neutral-500 bg-neutral-900 rounded-2xl border border-neutral-800">
                    Henüz aktif bir teklifiniz bulunmuyor.
                </div>
            @endforelse
        </div>
    @endif

    <!-- Teklif Verme Modalı -->
    @if($offerModalOpen && $selectedLoad)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('offerModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <div>
                        <h3 class="text-base font-bold text-white">Navlun Fiyat Teklifi Ver</h3>
                        <p class="text-xs text-neutral-400 mt-0.5">#NVL-{{ $selectedLoad->id }} numaralı ilana teklifinizi iletin.</p>
                    </div>
                    <button wire:click="$set('offerModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-3.5 bg-neutral-950 rounded-xl border border-neutral-800 text-xs space-y-1">
                    <div class="text-white font-bold">{{ $selectedLoad->pickup_location }} &rarr; {{ $selectedLoad->delivery_location }}</div>
                    <div class="text-neutral-400">Yük Sahibi Bütçesi: <b class="text-brand-400 font-mono">{{ number_format((float)$selectedLoad->price, 2, ',', '.') }} ₺</b></div>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Teklif Ettiğiniz Navlun Fiyatı (₺) <span class="text-brand-500">*</span></label>
                        <input type="number" wire:model="offered_price" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-base font-bold text-white font-mono focus:border-brand-500 focus:outline-none">
                        @error('offered_price') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Tahmini Teslimat Süresi (ETA) <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="estimated_eta" placeholder="Örn: 1 Gün 4 Saat" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Yük Sahibine Not (Opsiyonel)</label>
                        <textarea wire:model="offer_note" rows="3" placeholder="Aracım hazır, belirtilen saatte yükleme yapabilirim..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800 text-[11px] text-neutral-400 leading-relaxed">
                    💡 Teklif verdiğinizde ilan bu listeden kaybolur. İlk 1 saat içinde koşulsuz geri çekebilirsiniz.
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('offerModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="submitOffer" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        Teklifi İlet
                    </button>
                </div>

            </div>
        </div>
    @endif

    <!-- 1 Saat Sonrası Gerekçeli İptal Talebi Modalı -->
    @if($cancelRequestModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('cancelRequestModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-400">⏳</span>
                        <span>Gerekçeli İptal Talebi İlet</span>
                    </h3>
                    <button wire:click="$set('cancelRequestModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="p-3 bg-neutral-950 rounded-xl border border-amber-500/30 text-amber-300 leading-relaxed text-[11px]">
                        ⚠ Teklif verme anının üzerinden 1 saatten fazla süre geçtiği için teklifi doğrudan silemezsiniz. Lütfen geçerli bir mazeret belirtiniz. Talebiniz yük sahibinin onayına sunulacaktır.
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">İptal Gerekçeniz <span class="text-brand-500">*</span></label>
                        <textarea wire:model="cancellation_reason" rows="4" placeholder="Araçta teknik arıza oluştu, rotam değişti vb. geçerli sebebinizi açıklayınız..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('cancellation_reason') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('cancelRequestModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="submitCancelRequest" class="flex-1 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-black text-xs font-bold shadow-lg shadow-amber-500/20 transition-all">
                        Talebi Yük Sahibine İlet
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
