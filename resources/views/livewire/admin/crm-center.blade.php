<?php

use App\Jobs\SendAnnouncementJob;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Component;

/**
 * Duyuru (hizmet bildirimi): kullanıcı segmentine uygulama içi bildirim + e-posta. Yalnız süper yönetici gönderir;
 * gönderim kuyrukta parça parça yapılır (denetim Y12). Kupon sekmesi kaldırıldı: kuponlar ödeme akışında uygulanmıyordu (Y21).
 * Pazarlama kategorisi, kullanıcı tablosunda `marketing_consent_at` sütunu varsa yalnız rıza vermiş alıcılara gider (ETK/İYS).
 */
new class extends Component {
    public string $segmentRole = 'driver';

    public string $segmentKyc = 'all';

    public bool $segmentPremiumOnly = false;

    /** service: hizmet bildirimi (herkese) · marketing: ticari ileti (yalnız rıza verenlere; sütun yoksa gönderilmez) */
    public string $category = 'service';

    public string $subject = '';

    public string $message = '';

    public ?int $targetCount = null;

    public bool $confirmSend = false;

    public const CHUNK = 50;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage marketing'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['segmentRole', 'segmentKyc', 'segmentPremiumOnly', 'category'], true)) {
            $this->targetCount = null;
            $this->confirmSend = false;
        }
    }

    public static function marketingConsentAvailable(): bool
    {
        return Schema::hasColumn('users', 'marketing_consent_at');
    }

    private function segmentQuery()
    {
        $query = User::query()->where('is_active', true)->whereNull('banned_at')->whereNotNull('email');

        $roles = $this->segmentRole === 'all' ? ['driver', 'cargo_owner'] : [$this->segmentRole];
        $query->role($roles);

        if ($this->segmentRole === 'driver') {
            $query->whereHas('driverProfile', function ($q): void {
                $q->where('is_staff_view', false);
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

        // Ticari ileti yalnız açık rıza vermiş kullanıcıya gider; rıza sütunu henüz yoksa kimseye gitmez.
        if ($this->category === 'marketing') {
            self::marketingConsentAvailable() ? $query->whereNotNull('marketing_consent_at') : $query->whereRaw('1 = 0');
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
        if (! auth()->user()?->hasRole('super_admin')) {
            session()->flash('error_message', 'Duyuru göndermeyi yalnız süper yönetici yapabilir.');

            return;
        }

        $this->validate([
            'category' => 'required|in:service,marketing',
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
        if ($this->category === 'marketing' && ! self::marketingConsentAvailable()) {
            $this->addError('category', 'Pazarlama rızası henüz toplanmıyor; ticari ileti gönderilemez. Hizmet bildirimi seçin.');

            return;
        }

        $lines = preg_split('/\R{2,}/', trim($this->message)) ?: [trim($this->message)];
        $queued = 0;
        $jobs = 0;
        $subject = $this->subject;

        $this->segmentQuery()->select('id')->orderBy('id')->chunkById(self::CHUNK, function ($users) use (&$queued, &$jobs, $subject, $lines): void {
            SendAnnouncementJob::dispatch($users->pluck('id')->map(fn ($id) => (int) $id)->all(), $subject, $lines);
            $queued += $users->count();
            $jobs++;
        });

        ActivityLog::record('marketing.announcement', "Duyuru kuyruğa alındı: \"{$subject}\" ({$queued} alıcı, {$jobs} parça, kategori: {$this->category}, segment: {$this->segmentRole}/{$this->segmentKyc}".($this->segmentPremiumOnly ? '/premium' : '').')', auth()->id(), null, ['recipients' => $queued, 'jobs' => $jobs, 'category' => $this->category]);

        $this->reset(['subject', 'message', 'confirmSend', 'targetCount']);
        session()->flash('success_message', "Duyuru {$queued} alıcı için kuyruğa alındı ({$jobs} parça); kuyruk işçisi gönderir, teslim durumu Sistem Ayarları → E-posta sekmesinde görünür.");
    }

    public function with(): array
    {
        return [
            'isSuperAdmin' => (bool) auth()->user()?->hasRole('super_admin'),
            'consentAvailable' => self::marketingConsentAvailable(),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-6">
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
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Duyuru (hizmet bildirimi)</h1>
        <p class="page-subtitle">Kullanıcı segmentine uygulama içi bildirim ve e-posta. Hizmet bildirimi (bakım, kural değişikliği, güvenlik) herkese gider; ticari ileti yalnız açık rıza vermiş kullanıcılara gönderilebilir.</p>
    </div>

    @if(! $isSuperAdmin)
        <div class="apple-glass rounded-3xl p-6 text-xs text-neutral-500">Duyuru göndermek yalnız süper yöneticiye açıktır; hedef kitleyi sayabilirsiniz.</div>
    @endif

    <form wire:submit="sendAnnouncement" class="apple-glass rounded-3xl p-6 space-y-4 text-xs max-w-3xl">
        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Segmente duyuru</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="form-label">Kategori</label>
                <select wire:model.live="category" class="{{ $input }}">
                    <option value="service">Hizmet bildirimi (herkese)</option>
                    <option value="marketing">Ticari ileti (yalnız rıza verenlere)</option>
                </select>
                @error('category') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="form-label">Rol</label>
                <select wire:model.live="segmentRole" class="{{ $input }}">
                    <option value="driver">Şoförler</option>
                    <option value="cargo_owner">Yük sahipleri</option>
                    <option value="all">Her iki rol</option>
                </select>
            </div>
            <div>
                <label class="form-label">Belge durumu</label>
                <select wire:model.live="segmentKyc" class="{{ $input }}">
                    <option value="all">Tüm kullanıcılar</option>
                    <option value="approved">Yalnız belgeleri onaylılar</option>
                </select>
            </div>
            @if($segmentRole === 'driver')
                <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="segmentPremiumOnly"> Yalnız premium şoförler</label>
            @endif
        </div>
        @if($category === 'marketing' && ! $consentAvailable)
            <p class="text-[11px] text-amber-600">Pazarlama rızası henüz toplanmıyor (kayıt formunda ayrı onay kutusu gelince açılır); ticari ileti bu sürümde gönderilemez.</p>
        @endif
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
        @if($targetCount !== null && $targetCount > 0 && $isSuperAdmin)
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="confirmSend"> {{ $targetCount }} alıcıya gönderimi onaylıyorum</label>
            @error('confirmSend') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">
                <span wire:loading.remove wire:target="sendAnnouncement">Gönder</span>
                <span wire:loading wire:target="sendAnnouncement">Kuyruğa alınıyor</span>
            </button>
            <p class="text-[11px] text-neutral-400">Alıcılar 50'lik parçalarla kuyruğa alınır; kuyruk işçisi her alıcıya uygulama içi bildirim ve e-posta gönderir. Teslim edilemeyen adresler 10 dakikada bir yeniden denenir.</p>
        @elseif($targetCount === 0)
            <p class="text-[11px] text-amber-600">Seçilen segmentte alıcı yok.</p>
        @endif
    </form>
</div>
