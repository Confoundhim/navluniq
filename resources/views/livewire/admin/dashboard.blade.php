<?php

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\Dispute;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new class extends Component {
    public function with(): array
    {
        $user = auth()->user();
        $canFinance = $user->can('view financials');

        $roleLabels = [
            'super_admin' => 'Süper yönetici',
            'kyc_validator' => 'KYC doğrulayıcı',
            'financial_officer' => 'Finans sorumlusu',
            'support_agent' => 'Destek temsilcisi',
            'cargo_owner' => 'Yük sahibi',
            'driver' => 'Şoför',
        ];

        $usersByRole = Role::query()->withCount('users')->orderBy('name')->get()
            ->map(fn (Role $role) => ['label' => $roleLabels[$role->name] ?? $role->name, 'count' => (int) $role->users_count])
            ->all();

        $loadCounts = Load::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $loadsByStatus = collect(Load::STATUS_LABELS)
            ->map(fn ($label, $status) => ['label' => $label, 'count' => (int) ($loadCounts[$status] ?? 0)])
            ->values()->all();

        $pendingKyc = DriverProfile::query()->where('kyc_status', 'pending')->count()
            + CargoOwnerProfile::query()->where('kyc_status', 'pending')->count();

        $finance = null;
        if ($canFinance) {
            $paidOrders = PaymentOrder::query()->where('status', 'paid');
            $finance = [
                'payouts_pending' => (float) Payout::query()->whereIn('status', ['pending', 'processing'])->sum('net_amount'),
                'payouts_pending_count' => Payout::query()->whereIn('status', ['pending', 'processing'])->count(),
                'escrow' => (float) Load::query()->where('escrow_status', Load::ESCROW_PAID)->sum('price'),
                'escrow_on_hold' => (float) Load::query()->where('escrow_status', Load::ESCROW_ON_HOLD)->sum('price'),
                'paid_today_count' => (clone $paidOrders)->where('paid_at', '>=', now()->startOfDay())->count(),
                'paid_today_sum' => (float) (clone $paidOrders)->where('paid_at', '>=', now()->startOfDay())->sum('amount'),
                'paid_month_count' => (clone $paidOrders)->where('paid_at', '>=', now()->startOfMonth())->count(),
                'paid_month_sum' => (float) (clone $paidOrders)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            ];
        }

        return [
            'usersByRole' => $usersByRole,
            'loadsByStatus' => $loadsByStatus,
            'pendingKyc' => $pendingKyc,
            'openDisputes' => Dispute::query()->where('status', 'open')->count(),
            'finance' => $finance,
            'activities' => ActivityLog::query()->with('user')->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div wire:poll.8s class="max-w-7xl mx-auto space-y-8">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Genel Özet</h1>
        <p class="page-subtitle">Veriler sayfa her yüklendiğinde veritabanından okunur.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="apple-glass rounded-2xl p-5">
            <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Bekleyen KYC başvurusu</span>
            <p class="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{{ $pendingKyc }}</p>
            @can('view users')
                <a href="{{ route('admin.kyc') }}" wire:navigate class="text-[11px] text-brand-500 font-semibold mt-1 inline-block">KYC merkezine git</a>
            @endcan
        </div>
        <div class="apple-glass rounded-2xl p-5">
            <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Açık uyuşmazlık</span>
            <p class="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{{ $openDisputes }}</p>
            @can('manage disputes')
                <a href="{{ route('admin.disputes') }}" wire:navigate class="text-[11px] text-brand-500 font-semibold mt-1 inline-block">Uyuşmazlıklara git</a>
            @endcan
        </div>
        @if($finance)
            <div class="apple-glass rounded-2xl p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Ödeme bekleyen hakediş</span>
                <p class="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{{ number_format($finance['payouts_pending'], 2, ',', '.') }} ₺</p>
                <p class="text-[11px] text-neutral-500 mt-1">{{ $finance['payouts_pending_count'] }} kayıt</p>
            </div>
            <div class="apple-glass rounded-2xl p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Havuzda bloke navlun</span>
                <p class="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">{{ number_format($finance['escrow'], 2, ',', '.') }} ₺</p>
                <p class="text-[11px] text-neutral-500 mt-1">Uyuşmazlık nedeniyle askıda: {{ number_format($finance['escrow_on_hold'], 2, ',', '.') }} ₺</p>
            </div>
        @endif
    </div>

    @if($finance)
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="apple-glass rounded-2xl p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Bugün tahsil edilen ödeme emirleri</span>
                <p class="mt-2 text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($finance['paid_today_sum'], 2, ',', '.') }} ₺</p>
                <p class="text-[11px] text-neutral-500 mt-1">{{ $finance['paid_today_count'] }} sipariş</p>
            </div>
            <div class="apple-glass rounded-2xl p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Bu ay tahsil edilen ödeme emirleri</span>
                <p class="mt-2 text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($finance['paid_month_sum'], 2, ',', '.') }} ₺</p>
                <p class="text-[11px] text-neutral-500 mt-1">{{ $finance['paid_month_count'] }} sipariş</p>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Kullanıcılar (role göre)</h2>
            <div class="mt-4 space-y-2 text-xs">
                @forelse($usersByRole as $row)
                    <div class="flex items-center justify-between border-b border-neutral-100 dark:border-neutral-800/60 pb-2">
                        <span class="text-neutral-600 dark:text-neutral-300">{{ $row['label'] }}</span>
                        <span class="font-bold text-neutral-900 dark:text-white">{{ $row['count'] }}</span>
                    </div>
                @empty
                    <p class="text-neutral-500">Henüz rol tanımı yok.</p>
                @endforelse
            </div>
        </section>

        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">İlanlar (duruma göre)</h2>
            <div class="mt-4 space-y-2 text-xs">
                @foreach($loadsByStatus as $row)
                    <div class="flex items-center justify-between border-b border-neutral-100 dark:border-neutral-800/60 pb-2">
                        <span class="text-neutral-600 dark:text-neutral-300">{{ $row['label'] }}</span>
                        <span class="font-bold text-neutral-900 dark:text-white">{{ $row['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="apple-glass rounded-3xl p-6">
        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son işlemler</h2>
        <div class="responsive-scroll mt-4">
            <table class="table-cards w-full text-left text-xs">
                <thead>
                    <tr class="text-[11px] text-neutral-400 border-b border-neutral-100 dark:border-neutral-800/60">
                        <th class="py-2 pr-4">Zaman</th>
                        <th class="py-2 pr-4">Personel</th>
                        <th class="py-2 pr-4">İşlem</th>
                        <th class="py-2">Açıklama</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                    @forelse($activities as $log)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap text-neutral-500" data-label="Zaman">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap" data-label="Personel">{{ $log->user?->full_name ?? 'Sistem' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap font-mono text-[11px]" data-label="İşlem">{{ $log->action }}</td>
                            <td class="py-2 text-neutral-600 dark:text-neutral-300 tc-block" data-label="Açıklama">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-neutral-500">Henüz kayıtlı işlem yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
