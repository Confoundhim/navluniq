{{-- "Bu işi aldım" penceresi: HandlesExternalLoadActions trait'i ile çalışır. --}}
@props(['load'])
@if($load)
    <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
        <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeTake"></div>
        <form wire:submit.prevent="submitTake" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
            <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Bu işi aldım</h3>
                <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $load->pickup_location ?: 'Belirtilmemiş' }} &rarr; {{ $load->delivery_location ?: 'Belirtilmemiş' }}@if($load->goods_type) · {{ $load->goods_type }}@endif</p>
            </div>
            <p class="text-neutral-600 dark:text-neutral-300 leading-relaxed">İlan sahibiyle anlaştıysanız işi kaydedin. Teslim tarihinden itibaren <strong>{{ $load->delivery_location ?: 'varış yeriniz' }}</strong> çevresinden çıkan, aracınıza uyan yeni ilanlar size bildirilir; boş dönmezsiniz.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="form-label">Yükleme tarihi</label>
                    <input type="date" wire:model="takePickupDate" class="form-input">
                    @error('takePickupDate') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">Tahmini teslim tarihi</label>
                    <input type="date" wire:model="takeDeliveryDate" class="form-input">
                    @error('takeDeliveryDate') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" wire:model="takeNotify" class="rounded mt-0.5">
                <span class="text-neutral-700 dark:text-neutral-200">Dönüş yükü çıkınca bana bildir <span class="text-neutral-400">(uygulama içi ve e-posta; İşlerim'den kapatabilirsiniz)</span></span>
            </label>
            <div class="flex gap-3 pt-2">
                <button type="button" wire:click="closeTake" class="btn-secondary flex-1">Vazgeç</button>
                <button type="submit" class="btn-primary flex-1" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submitTake">İşi kaydet</span>
                    <span wire:loading wire:target="submitTake">Kaydediliyor...</span>
                </button>
            </div>
        </form>
    </div>
@endif
