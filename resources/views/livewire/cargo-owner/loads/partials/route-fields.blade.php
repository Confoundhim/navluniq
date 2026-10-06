{{--
    Rota alanları (ilan oluşturma 1. adım ve ilan düzenleme): adres defteri, il/ilçe seçici, gizli açık adres, yükleme yetkilisi, tarihler.
    $restricted = true (bekleyen teklif var): il/ilçe değişmez; açık adres, yetkili ve tarihler değişir.
--}}
@php $restricted = $restricted ?? false; @endphp
<div class="space-y-6">
    @if($pickupAddresses->isNotEmpty() || $deliveryAddresses->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="form-label">Kayıtlı yükleme adresi</label>
                <select wire:model.live="selected_saved_pickup" class="form-input" @disabled($restricted)>
                    <option value="">Adres defterinden seç</option>
                    @foreach($pickupAddresses as $addr)
                        <option value="{{ $addr->id }}">{{ $addr->title }} ({{ $addr->district }} / {{ $addr->city }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label">Kayıtlı teslimat adresi</label>
                <select wire:model.live="selected_saved_delivery" class="form-input" @disabled($restricted)>
                    <option value="">Adres defterinden seç</option>
                    @foreach($deliveryAddresses as $addr)
                        <option value="{{ $addr->id }}">{{ $addr->title }} ({{ $addr->district }} / {{ $addr->city }})</option>
                    @endforeach
                </select>
            </div>
        </div>
    @else
        <p class="text-[11px] text-neutral-500">Sık kullandığınız noktaları <a href="{{ route('cargo-owner.address-book.index') }}" wire:navigate class="text-brand-400 hover:underline">adres defterine</a> kaydederseniz buradan tek dokunuşla seçersiniz.</p>
    @endif

    <div class="p-4 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-neutral-50/60 dark:bg-neutral-950/40 space-y-4">
        <div class="flex items-center gap-2 text-xs font-bold text-neutral-800 dark:text-neutral-100">
            <span class="w-2 h-2 rounded-full bg-brand-500"></span>
            <span>Yükleme (çıkış)</span>
        </div>
        <x-place-picker province-model="pickup_province_code" district-model="pickup_district" :province-code="$pickup_province_code" label="Yükleme" :disabled="$restricted" />
        <div>
            <label class="form-label">Açık adres <span class="text-neutral-400 font-normal">(yalnız ödeme sonrası atanan şoför görür)</span></label>
            <textarea wire:model="pickup_address_private" rows="2" maxlength="1000" placeholder="Mahalle, cadde, kapı numarası, depo adı" class="form-input"></textarea>
            @error('pickup_address_private') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="form-label">Yükleme yetkilisi <span class="text-neutral-400 font-normal">(isteğe bağlı)</span></label>
                <input type="text" wire:model="pickup_contact_name" maxlength="120" class="form-input" autocomplete="off">
                @error('pickup_contact_name') <span class="form-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="form-label">Yetkili telefonu <span class="text-neutral-400 font-normal">(isteğe bağlı)</span></label>
                <input type="text" wire:model="pickup_contact_phone" inputmode="tel" placeholder="05XX XXX XX XX" class="form-input tabular-nums" autocomplete="off">
                @error('pickup_contact_phone') <span class="form-error">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>

    <div class="p-4 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-neutral-50/60 dark:bg-neutral-950/40 space-y-4">
        <div class="flex items-center gap-2 text-xs font-bold text-neutral-800 dark:text-neutral-100">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span>Teslimat (varış)</span>
        </div>
        <x-place-picker province-model="delivery_province_code" district-model="delivery_district" :province-code="$delivery_province_code" label="Teslimat" :disabled="$restricted" />
        <div>
            <label class="form-label">Açık adres <span class="text-neutral-400 font-normal">(yalnız ödeme sonrası atanan şoför görür)</span></label>
            <textarea wire:model="delivery_address_private" rows="2" maxlength="1000" placeholder="Mahalle, cadde, kapı numarası, depo adı" class="form-input"></textarea>
            @error('delivery_address_private') <span class="form-error">{{ $message }}</span> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="form-label">Yükleme tarihi <span class="text-brand-500">*</span></label>
            <input type="date" wire:model="pickup_date" min="{{ now()->format('Y-m-d') }}" class="form-input">
            @error('pickup_date') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">En geç teslim tarihi <span class="text-neutral-400 font-normal">(isteğe bağlı)</span></label>
            <input type="date" wire:model="delivery_date" class="form-input">
            @error('delivery_date') <span class="form-error">{{ $message }}</span> @enderror
        </div>
    </div>
</div>
