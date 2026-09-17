<?php

use App\Http\Middleware\FirewallMiddleware;
use App\Models\ActivityLog;
use App\Models\BannedIp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $ipAddress = '';

    public string $reason = '';

    public string $banType = 'permanent';

    public string $banDays = '7';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage settings'), 403);
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function ban(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'ipAddress' => 'required|ip',
            'reason' => 'required|string|min:5|max:255',
            'banType' => 'required|in:permanent,temporary',
            'banDays' => 'required_if:banType,temporary|nullable|integer|min:1|max:365',
        ], [
            'ipAddress.ip' => 'Geçerli bir IPv4 veya IPv6 adresi girin.',
            'reason.min' => 'Gerekçe en az 5 karakter olmalıdır.',
        ]);

        $ip = trim($this->ipAddress);

        if ($ip === (string) request()->ip()) {
            $this->addError('ipAddress', 'Şu an bağlı olduğunuz IP adresi yasaklanamaz.');

            return;
        }
        if ($this->isPrivateOrReserved($ip)) {
            $this->addError('ipAddress', 'Özel veya ayrılmış aralıktaki adresler (yerel ağ, geri döngü) yasaklanamaz.');

            return;
        }

        $until = $this->banType === 'temporary' ? now()->addDays((int) $this->banDays) : null;

        $existing = BannedIp::query()->where('ip_address', $ip)->first();
        if ($existing && $existing->isBanned()) {
            $this->addError('ipAddress', 'Bu adres zaten yasaklı.');

            return;
        }

        $ban = BannedIp::updateOrCreate(
            ['ip_address' => $ip],
            ['reason' => trim($this->reason), 'banned_by' => auth()->id(), 'banned_until' => $until]
        );
        Cache::forget(FirewallMiddleware::CACHE_KEY);
        ActivityLog::record('firewall.banned', "IP yasaklandı: {$ip} (".($until ? $until->format('d.m.Y H:i').' tarihine kadar' : 'kalıcı').')', auth()->id(), $ban);

        $this->reset(['ipAddress', 'reason']);
        session()->flash('success_message', 'IP adresi yasaklandı; engel en geç bir dakika içinde tüm sunucularda geçerli olur.');
    }

    public function unban(int $banId): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $ban = BannedIp::query()->find($banId);
        if (! $ban) {
            return;
        }

        $ban->delete();
        Cache::forget(FirewallMiddleware::CACHE_KEY);
        ActivityLog::record('firewall.unbanned', "IP yasağı kaldırıldı: {$ban->ip_address}", auth()->id());
        session()->flash('success_message', 'Yasak kaldırıldı.');
    }

    public function with(): array
    {
        $active = BannedIp::query()
            ->where(fn ($q) => $q->whereNull('banned_until')->orWhere('banned_until', '>', now()))
            ->latest('id')->paginate(15, ['*'], 'activePage');
        $expired = BannedIp::query()
            ->whereNotNull('banned_until')->where('banned_until', '<=', now())
            ->latest('banned_until')->paginate(15, ['*'], 'expiredPage');

        $staffIds = $active->getCollection()->pluck('banned_by')->merge($expired->getCollection()->pluck('banned_by'))->filter()->unique();
        $staff = User::query()->whereIn('id', $staffIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return [
            'active' => $active,
            'expired' => $expired,
            'staff' => $staff,
            'currentIp' => (string) request()->ip(),
        ];
    }
}; ?>

<div wire:poll.15s class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Güvenlik Duvarı</h1>
        <p class="page-subtitle">Yasaklı adresler her istekte denetlenir; liste 60 saniye önbellekte tutulur ve her değişiklikte temizlenir.</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
        <form wire:submit="ban" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">IP yasakla</h2>
            <div>
                <label class="form-label">IP adresi</label>
                <input type="text" wire:model="ipAddress" placeholder="203.0.113.10" class="{{ $input }} font-mono">
                @error('ipAddress') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                <span class="text-[11px] text-neutral-400">Bağlı olduğunuz adres: {{ $currentIp }}</span>
            </div>
            <div>
                <label class="form-label">Gerekçe</label>
                <input type="text" wire:model="reason" class="{{ $input }}">
                @error('reason') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="form-label">Süre</label>
                    <select wire:model.live="banType" class="{{ $input }}">
                        <option value="permanent">Kalıcı</option>
                        <option value="temporary">Geçici</option>
                    </select>
                </div>
                @if($banType === 'temporary')
                    <div>
                        <label class="form-label">Gün</label>
                        <input type="number" min="1" max="365" wire:model="banDays" class="{{ $input }}">
                        @error('banDays') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>
            <button type="submit" wire:loading.attr="disabled" class="py-2.5 px-5 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold">Yasakla</button>

            <div class="pt-4 border-t border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400 space-y-1">
                <p class="font-semibold text-neutral-500">Bilgi: kod içinde sabit hız sınırları</p>
                <p>Yönetici girişi: 5 deneme / dakika (IP + kimlik). OTP: 3 gönderim / dakika, 5 hatalı deneme. Konum bildirimi: 60 istek / dakika. Bu değerler bu ekrandan değiştirilemez.</p>
            </div>
        </form>

        <div class="xl:col-span-2 space-y-6">
            <div class="apple-glass rounded-3xl overflow-hidden">
                <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50 text-sm font-bold">Aktif yasaklar</div>
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">IP</th>
                                <th class="p-4">Gerekçe</th>
                                <th class="p-4">Bitiş</th>
                                <th class="p-4">Ekleyen</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($active as $ban)
                                <tr>
                                    <td class="p-4 font-mono font-bold">{{ $ban->ip_address }}</td>
                                    <td class="p-4">{{ $ban->reason }}</td>
                                    <td class="p-4 whitespace-nowrap">{{ $ban->banned_until?->format('d.m.Y H:i') ?? 'Kalıcı' }}</td>
                                    <td class="p-4 text-neutral-500">{{ $staff[$ban->banned_by]?->full_name ?? '—' }}<div class="text-[11px] text-neutral-400">{{ $ban->created_at?->format('d.m.Y H:i') }}</div></td>
                                    <td class="p-4"><button type="button" wire:click="unban({{ $ban->id }})" wire:confirm="Yasak kaldırılacak. Devam edilsin mi?" class="text-brand-500 font-semibold">Kaldır</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-10 text-center text-neutral-500">Aktif yasak yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $active->links() }}</div>
            </div>

            <div class="apple-glass rounded-3xl overflow-hidden">
                <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50 text-sm font-bold">Süresi dolmuş yasaklar</div>
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">IP</th>
                                <th class="p-4">Gerekçe</th>
                                <th class="p-4">Bitti</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($expired as $ban)
                                <tr>
                                    <td class="p-4 font-mono">{{ $ban->ip_address }}</td>
                                    <td class="p-4">{{ $ban->reason }}</td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500">{{ $ban->banned_until?->format('d.m.Y H:i') }}</td>
                                    <td class="p-4"><button type="button" wire:click="unban({{ $ban->id }})" class="text-neutral-500 font-semibold">Kaydı sil</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-10 text-center text-neutral-500">Süresi dolmuş yasak yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $expired->links() }}</div>
            </div>
        </div>
    </div>
</div>
