<?php

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $activeTab = 'coupons';

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $type = 'percentage';

    public string $value = '';

    public string $usageLimit = '';

    public string $expiresAt = '';

    public string $segmentRole = 'driver';

    public string $segmentKyc = 'all';

    public bool $segmentPremiumOnly = false;

    public string $subject = '';

    public string $message = '';

    public ?int $targetCount = null;

    public bool $confirmSend = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage marketing'), 403);
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedSegmentRole(): void
    {
        $this->targetCount = null;
        $this->confirmSend = false;
    }

    public function updatedSegmentKyc(): void
    {
        $this->targetCount = null;
        $this->confirmSend = false;
    }

    public function updatedSegmentPremiumOnly(): void
    {
        $this->targetCount = null;
        $this->confirmSend = false;
    }

    public function editCoupon(int $couponId): void
    {
        $coupon = Coupon::query()->find($couponId);
        if (! $coupon) {
            return;
        }
        $this->editingId = $coupon->id;
        $this->code = $coupon->code;
        $this->type = $coupon->type;
        $this->value = (string) $coupon->value;
        $this->usageLimit = $coupon->usage_limit === null ? '' : (string) $coupon->usage_limit;
        $this->expiresAt = $coupon->expires_at?->format('Y-m-d') ?? '';
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'code', 'type', 'value', 'usageLimit', 'expiresAt']);
        $this->resetErrorBag();
    }

    public function saveCoupon(): void
    {
        if (! auth()->user()?->can('manage marketing')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($this->editingId)->whereNull('deleted_at')],
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0.01|max:'.($this->type === 'percentage' ? '100' : '1000000'),
            'usageLimit' => 'nullable|integer|min:1|max:1000000',
            'expiresAt' => 'nullable|date|after:today',
        ], [
            'code.unique' => 'Bu kupon kodu zaten tanımlı.',
            'code.regex' => 'Kupon kodu yalnız harf, rakam, tire ve alt çizgi içerebilir.',
            'value.max' => 'Yüzde indirim 100 değerini aşamaz.',
            'expiresAt.after' => 'Son kullanma tarihi bugünden sonra olmalıdır.',
        ]);

        $payload = [
            'code' => mb_strtoupper($this->code, 'UTF-8'),
            'type' => $this->type,
            'value' => round((float) str_replace(',', '.', $this->value), 2),
            'usage_limit' => $this->usageLimit === '' ? null : (int) $this->usageLimit,
            'expires_at' => $this->expiresAt === '' ? null : $this->expiresAt.' 23:59:59',
        ];

        if ($this->editingId) {
            $coupon = Coupon::query()->find($this->editingId);
            if (! $coupon) {
                session()->flash('error_message', 'Kupon bulunamadı.');

                return;
            }
            $coupon->update($payload);
            ActivityLog::record('coupon.updated', "Kupon {$coupon->code} güncellendi", auth()->id(), $coupon);
            session()->flash('success_message', 'Kupon güncellendi.');
        } else {
            $coupon = Coupon::create($payload + ['is_active' => true, 'used_count' => 0]);
            ActivityLog::record('coupon.created', "Kupon {$coupon->code} oluşturuldu", auth()->id(), $coupon);
            session()->flash('success_message', 'Kupon oluşturuldu ve aktif.');
        }

        $this->cancelEdit();
    }

    public function toggleCoupon(int $couponId): void
    {
        if (! auth()->user()?->can('manage marketing')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $coupon = Coupon::query()->find($couponId);
        if (! $coupon) {
            return;
        }
        $coupon->update(['is_active' => ! $coupon->is_active]);
        ActivityLog::record('coupon.toggled', "Kupon {$coupon->code} ".($coupon->is_active ? 'aktif edildi' : 'pasife alındı'), auth()->id(), $coupon);
    }

    public function deleteCoupon(int $couponId): void
    {
        if (! auth()->user()?->can('manage marketing')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $coupon = Coupon::query()->find($couponId);
        if (! $coupon) {
            return;
        }
        $coupon->delete();
        ActivityLog::record('coupon.deleted', "Kupon {$coupon->code} silindi", auth()->id(), $coupon);
        if ($this->editingId === $couponId) {
            $this->cancelEdit();
        }
        session()->flash('success_message', 'Kupon silindi.');
    }

    private function segmentQuery()
    {
        $query = User::query()->where('is_active', true)->whereNull('banned_at')->whereNotNull('email');

        $roles = $this->segmentRole === 'all' ? ['driver', 'cargo_owner'] : [$this->segmentRole];
        $query->role($roles);

        if ($this->segmentRole === 'driver') {
            $query->whereHas('driverProfile', function ($q): void {
                if ($this->segmentKyc === 'approved') {
                    $q->where('kyc_status', 'approved');
                }
                if ($this->segmentPremiumOnly) {
                    $q->where('premium_until', '>', now());
                }
            });
        } elseif ($this->segmentRole === 'cargo_owner' && $this->segmentKyc === 'approved') {
            $query->whereHas('cargoOwnerProfile', fn ($q) => $q->where('kyc_status', 'approved'));
        }

        return $query;
    }

    public function countTargets(): void
    {
        $this->targetCount = $this->segmentQuery()->count();
        $this->confirmSend = false;
    }

    public function sendAnnouncement(): void
    {
        if (! auth()->user()?->can('manage marketing')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'subject' => 'required|string|min:5|max:120',
            'message' => 'required|string|min:20|max:4000',
            'confirmSend' => 'accepted',
        ], [
            'confirmSend.accepted' => 'Göndermeden önce hedef sayısını onaylayın.',
        ]);

        if ($this->targetCount === null) {
            $this->addError('confirmSend', 'Önce hedef kitleyi sayın.');

            return;
        }

        $notifications = app(NotificationService::class);
        $lines = preg_split('/\R{2,}/', trim($this->message)) ?: [trim($this->message)];
        $sent = 0;

        $this->segmentQuery()->select(['id', 'email', 'first_name', 'last_name'])->chunkById(100, function ($users) use (&$sent, $notifications, $lines): void {
            foreach ($users as $user) {
                $notifications->notify($user, $this->subject, $lines);
                $sent++;
            }
        });

        ActivityLog::record('marketing.announcement', "Duyuru e-postası gönderildi: \"{$this->subject}\" ({$sent} alıcı, segment: {$this->segmentRole}/{$this->segmentKyc}".($this->segmentPremiumOnly ? '/premium' : '').')', auth()->id(), null, ['recipients' => $sent]);

        $this->reset(['subject', 'message', 'confirmSend', 'targetCount']);
        session()->flash('success_message', "Duyuru {$sent} alıcıya e-posta ile gönderildi. Teslim edilemeyen adresler uygulama günlüğüne yazılır.");
    }

    public function with(): array
    {
        return [
            'coupons' => $this->activeTab === 'coupons' ? Coupon::query()->latest('id')->paginate(15) : null,
        ];
    }
}; ?>

<div wire:poll.10s class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Pazarlama ve CRM</h1>
        <p class="page-subtitle">İndirim kuponları ve kullanıcı segmentlerine e-posta duyurusu.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        <button type="button" wire:click="$set('activeTab', 'coupons')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'coupons' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Kuponlar</button>
        <button type="button" wire:click="$set('activeTab', 'announce')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'announce' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">E-posta duyurusu</button>
    </div>

    @if($activeTab === 'coupons')
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="saveCoupon" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">{{ $editingId ? 'Kuponu düzenle' : 'Yeni kupon' }}</h2>
                <div>
                    <label class="form-label">Kod</label>
                    <input type="text" wire:model="code" placeholder="ORNEK10" class="{{ $input }} uppercase">
                    @error('code') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="form-label">Tür</label>
                        <select wire:model.live="type" class="{{ $input }}">
                            <option value="percentage">Yüzde</option>
                            <option value="fixed">Sabit tutar (₺)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Değer</label>
                        <input type="text" inputmode="decimal" wire:model="value" class="{{ $input }}">
                        @error('value') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="form-label">Kullanım limiti</label>
                        <input type="number" min="1" wire:model="usageLimit" placeholder="Sınırsız" class="{{ $input }}">
                        @error('usageLimit') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="form-label">Son kullanma</label>
                        <input type="date" wire:model="expiresAt" class="{{ $input }}">
                        @error('expiresAt') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">{{ $editingId ? 'Kaydet' : 'Oluştur' }}</button>
                    @if($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn-apple-secondary py-2.5 px-5 text-xs">Vazgeç</button>
                    @endif
                </div>
                <p class="text-[11px] text-neutral-400">Kupon kullanımı ödeme akışında bu sürümde uygulanmaz; kayıtlar ileride kullanılmak üzere tutulur.</p>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Kod</th>
                                <th class="p-4">İndirim</th>
                                <th class="p-4">Kullanım</th>
                                <th class="p-4">Son tarih</th>
                                <th class="p-4">Durum</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($coupons as $coupon)
                                <tr>
                                    <td class="p-4 font-mono font-bold">{{ $coupon->code }}</td>
                                    <td class="p-4">{{ $coupon->type === 'percentage' ? '%'.number_format((float) $coupon->value, 2, ',', '.') : number_format((float) $coupon->value, 2, ',', '.').' ₺' }}</td>
                                    <td class="p-4">{{ (int) $coupon->used_count }} / {{ $coupon->usage_limit === null ? 'Sınırsız' : $coupon->usage_limit }}</td>
                                    <td class="p-4 whitespace-nowrap">{{ $coupon->expires_at?->format('d.m.Y') ?? 'Süresiz' }}</td>
                                    <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $coupon->isValid() ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500' }}">{{ $coupon->isValid() ? 'Geçerli' : ($coupon->is_active ? 'Süresi doldu / limit' : 'Pasif') }}</span></td>
                                    <td class="p-4 whitespace-nowrap space-x-2">
                                        <button type="button" wire:click="editCoupon({{ $coupon->id }})" class="text-brand-500 font-semibold">Düzenle</button>
                                        <button type="button" wire:click="toggleCoupon({{ $coupon->id }})" class="text-neutral-500 font-semibold">{{ $coupon->is_active ? 'Pasife al' : 'Aktif et' }}</button>
                                        <button type="button" wire:click="deleteCoupon({{ $coupon->id }})" wire:confirm="Kupon silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-10 text-center text-neutral-500">Henüz kupon tanımlanmadı.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $coupons->links() }}</div>
            </div>
        </div>
    @endif

    @if($activeTab === 'announce')
        <form wire:submit="sendAnnouncement" class="apple-glass rounded-3xl p-6 space-y-4 text-xs max-w-3xl">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Segmente e-posta duyurusu</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="form-label">Rol</label>
                    <select wire:model.live="segmentRole" class="{{ $input }}">
                        <option value="driver">Şoförler</option>
                        <option value="cargo_owner">Yük sahipleri</option>
                        <option value="all">Her iki rol</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">KYC</label>
                    <select wire:model.live="segmentKyc" class="{{ $input }}">
                        <option value="all">Tüm kullanıcılar</option>
                        <option value="approved">Yalnız KYC onaylılar</option>
                    </select>
                </div>
                @if($segmentRole === 'driver')
                    <label class="flex items-center gap-2 mt-5"><input type="checkbox" wire:model.live="segmentPremiumOnly"> Yalnız premium şoförler</label>
                @endif
            </div>
            <div>
                <label class="form-label">Konu</label>
                <input type="text" wire:model="subject" class="{{ $input }}">
                @error('subject') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="form-label">Mesaj (boş satırla paragraf ayırın)</label>
                <textarea wire:model="message" rows="6" class="{{ $input }}"></textarea>
                @error('message') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <button type="button" wire:click="countTargets" wire:loading.attr="disabled" class="btn-apple-secondary py-2 px-4 text-xs">Hedef kitleyi say</button>
                @if($targetCount !== null)
                    <span class="font-semibold">{{ $targetCount }} alıcı bulundu</span>
                @endif
            </div>
            @if($targetCount !== null && $targetCount > 0)
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="confirmSend"> {{ $targetCount }} alıcıya gönderimi onaylıyorum</label>
                @error('confirmSend') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">
                    <span wire:loading.remove wire:target="sendAnnouncement">Gönder</span>
                    <span wire:loading wire:target="sendAnnouncement">Gönderiliyor, lütfen bekleyin</span>
                </button>
                <p class="text-[11px] text-neutral-400">E-postalar 100'erlik gruplar halinde bu istek içinde gönderilir; büyük segmentlerde işlem birkaç dakika sürebilir. SMS veya anlık bildirim kanalı bu sürümde yoktur.</p>
            @elseif($targetCount === 0)
                <p class="text-[11px] text-amber-600">Seçilen segmentte alıcı yok.</p>
            @endif
        </form>
    @endif
</div>
