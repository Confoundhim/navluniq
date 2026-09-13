<?php

use App\Models\Language;
use Livewire\Volt\Component;

new class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage cms'), 403);
    }

    public function with(): array
    {
        return [
            'locale' => app()->getLocale(),
            'fallback' => (string) config('app.fallback_locale'),
            'languages' => Language::query()->orderByDesc('is_default')->orderBy('code')->get(),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Çoklu Dil</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Çoklu dil desteği bu sürümde etkin değil.</p>
    </div>

    <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
        <p class="text-neutral-600 dark:text-neutral-300">Arayüz metinleri tek dilde (Türkçe) sunulur; çeviri düzenleme ve dil değiştirme özellikleri bu sürümde bulunmaz. Bu sayfa yalnız mevcut yapılandırmayı gösterir.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40">
                <span class="text-neutral-400 block">Etkin dil</span>
                <span class="font-bold text-neutral-900 dark:text-white">{{ $locale }}</span>
            </div>
            <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40">
                <span class="text-neutral-400 block">Yedek dil</span>
                <span class="font-bold text-neutral-900 dark:text-white">{{ $fallback }}</span>
            </div>
        </div>
        @if($languages->isNotEmpty())
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Veritabanındaki dil kayıtları (uygulama tarafından kullanılmıyor)</span>
                <ul class="mt-2 space-y-1">
                    @foreach($languages as $language)
                        <li class="flex justify-between border-b border-neutral-100 dark:border-neutral-800/60 pb-1"><span>{{ $language->name }} <span class="font-mono text-neutral-400">{{ $language->code }}</span></span><span class="text-neutral-400">{{ $language->is_default ? 'varsayılan' : '' }} {{ $language->is_active ? 'aktif' : 'pasif' }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
