<?php

use App\Models\ActivityLog;
use App\Models\CmsContent;
use App\Models\Faq;
use App\Models\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public const TEXT_KEYS = [
        'slider_owner_title' => 'Ana sayfa: yük sahibi başlığı',
        'slider_owner_desc' => 'Ana sayfa: yük sahibi açıklaması',
        'slider_driver_title' => 'Ana sayfa: şoför başlığı',
        'slider_driver_desc' => 'Ana sayfa: şoför açıklaması',
        'hakkimizda_ozet' => 'Hakkımızda özeti',
        'footer_slogan' => 'Alt bilgi sloganı',
        'etbis_code' => 'ETBİS kodu',
        'contact_whatsapp' => 'İletişim WhatsApp numarası',
        'social_instagram' => 'Instagram bağlantısı',
        'social_whatsapp' => 'WhatsApp bağlantısı',
        'social_telegram' => 'Telegram bağlantısı',
    ];

    public const CONTRACT_KEYS = [
        'contract_kvkk' => 'KVKK aydınlatma metni',
        'contract_terms' => 'Kullanıcı sözleşmesi',
        'contract_privacy' => 'Gizlilik politikası',
        'contract_distance_sale' => 'Mesafeli satış sözleşmesi',
        'contract_cancellation' => 'İptal ve iade politikası',
    ];

    public string $activeTab = 'texts';

    /** @var array<string, string> */
    public array $texts = [];

    /** @var array<string, string> */
    public array $contracts = [];

    #[Locked]
    public ?int $faqId = null;

    public string $faqQuestion = '';

    public string $faqAnswer = '';

    public string $faqOrder = '0';

    public bool $faqActive = true;

    #[Locked]
    public ?int $pageId = null;

    public string $pageTitle = '';

    public string $pageSlug = '';

    public string $pageContent = '';

    public string $pageStatus = 'draft';

    public bool $pageActive = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage cms'), 403);
        $this->loadValues();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    private function loadValues(): void
    {
        foreach (array_keys(self::TEXT_KEYS) as $key) {
            $this->texts[$key] = (string) CmsContent::getVal($key, '');
        }
        foreach (array_keys(self::CONTRACT_KEYS) as $key) {
            $this->contracts[$key] = (string) CmsContent::getVal($key, '');
        }
    }

    /** İzin listesi tabanlı HTML temizliği (HTMLPurifier). */
    private function sanitizeHtml(string $html): string
    {
        return \App\Support\HtmlSanitizer::clean($html);
    }

    private function storeKeys(array $values, array $labels, string $group): int
    {
        $changed = 0;
        foreach ($labels as $key => $label) {
            $new = trim((string) ($values[$key] ?? ''));
            $old = (string) CmsContent::getVal($key, '');
            if ($new === $old) {
                continue;
            }
            CmsContent::setVal($key, $new === '' ? null : $new, auth()->id());
            $changed++;
        }
        if ($changed > 0) {
            ActivityLog::record('cms.updated', "{$group}: {$changed} alan güncellendi", auth()->id());
        }

        return $changed;
    }

    public function saveTexts(): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'texts.*' => 'nullable|string|max:2000',
            'texts.social_instagram' => 'nullable|url|max:255',
            'texts.social_whatsapp' => 'nullable|url|max:255',
            'texts.social_telegram' => 'nullable|url|max:255',
        ], ['texts.*.url' => 'Bağlantı https:// ile başlayan tam bir adres olmalıdır.']);

        $changed = $this->storeKeys($this->texts, self::TEXT_KEYS, 'Site metinleri');
        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} alan güncellendi." : 'Değişiklik yok.');
    }

    public function saveContracts(): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate(['contracts.*' => 'nullable|string|max:200000']);

        $clean = [];
        foreach ($this->contracts as $key => $html) {
            $clean[$key] = $this->sanitizeHtml((string) $html);
        }

        $changed = $this->storeKeys($clean, self::CONTRACT_KEYS, 'Sözleşmeler');
        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} sözleşme güncellendi. Betik ve olay öznitelikleri kaydedilmeden temizlendi." : 'Değişiklik yok.');
    }

    public function editFaq(int $id): void
    {
        $faq = Faq::query()->find($id);
        if (! $faq) {
            return;
        }
        $this->faqId = $faq->id;
        $this->faqQuestion = $faq->question;
        $this->faqAnswer = $faq->answer;
        $this->faqOrder = (string) $faq->order_num;
        $this->faqActive = (bool) $faq->is_active;
        $this->resetErrorBag();
    }

    public function resetFaq(): void
    {
        $this->reset(['faqId', 'faqQuestion', 'faqAnswer']);
        $this->faqOrder = (string) ((int) Faq::query()->max('order_num') + 1);
        $this->faqActive = true;
        $this->resetErrorBag();
    }

    public function saveFaq(): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'faqQuestion' => 'required|string|min:5|max:255',
            'faqAnswer' => 'required|string|min:10|max:5000',
            'faqOrder' => 'required|integer|min:0|max:9999',
        ]);

        $payload = ['question' => trim($this->faqQuestion), 'answer' => trim($this->faqAnswer), 'order_num' => (int) $this->faqOrder, 'is_active' => $this->faqActive, 'updated_by' => auth()->id()];

        if ($this->faqId) {
            Faq::query()->whereKey($this->faqId)->update($payload);
            ActivityLog::record('faq.updated', "SSS #{$this->faqId} güncellendi", auth()->id());
            session()->flash('success_message', 'Soru güncellendi.');
        } else {
            $faq = Faq::create($payload);
            ActivityLog::record('faq.created', "SSS #{$faq->id} eklendi", auth()->id(), $faq);
            session()->flash('success_message', 'Soru eklendi.');
        }

        $this->resetFaq();
    }

    public function toggleFaq(int $id): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $faq = Faq::query()->find($id);
        if ($faq) {
            $faq->update(['is_active' => ! $faq->is_active, 'updated_by' => auth()->id()]);
        }
    }

    public function deleteFaq(int $id): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        Faq::query()->whereKey($id)->delete();
        ActivityLog::record('faq.deleted', "SSS #{$id} silindi", auth()->id());
        if ($this->faqId === $id) {
            $this->resetFaq();
        }
        session()->flash('success_message', 'Soru silindi.');
    }

    public function editPage(int $id): void
    {
        $page = Page::query()->find($id);
        if (! $page) {
            return;
        }
        $this->pageId = $page->id;
        $this->pageTitle = $page->title;
        $this->pageSlug = $page->slug;
        $this->pageContent = (string) $page->content;
        $this->pageStatus = $page->status;
        $this->pageActive = (bool) $page->is_active;
        $this->resetErrorBag();
    }

    public function resetPageForm(): void
    {
        $this->reset(['pageId', 'pageTitle', 'pageSlug', 'pageContent', 'pageStatus', 'pageActive']);
        $this->resetErrorBag();
    }

    public function savePage(): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $slug = Str::slug($this->pageSlug !== '' ? $this->pageSlug : $this->pageTitle);
        $this->pageSlug = $slug;

        $this->validate([
            'pageTitle' => 'required|string|min:3|max:255',
            'pageSlug' => ['required', 'string', 'max:255', Rule::unique('pages', 'slug')->ignore($this->pageId)->whereNull('deleted_at')],
            'pageContent' => 'required|string|min:10|max:200000',
            'pageStatus' => 'required|in:draft,published',
        ], ['pageSlug.unique' => 'Bu kısa ad başka bir sayfada kullanılıyor.']);

        $payload = [
            'title' => trim($this->pageTitle),
            'slug' => $slug,
            'content' => $this->sanitizeHtml($this->pageContent),
            'status' => $this->pageStatus,
            'is_active' => $this->pageActive,
            'updated_by' => auth()->id(),
        ];

        if ($this->pageId) {
            $page = Page::query()->find($this->pageId);
            if (! $page) {
                session()->flash('error_message', 'Sayfa bulunamadı.');

                return;
            }
            $payload['published_at'] = $this->pageStatus === 'published' ? ($page->published_at ?? now()) : null;
            $page->update($payload);
            ActivityLog::record('page.updated', "Sayfa #{$page->id} ({$slug}) güncellendi", auth()->id(), $page);
            session()->flash('success_message', 'Sayfa güncellendi.');
        } else {
            $payload['published_at'] = $this->pageStatus === 'published' ? now() : null;
            $page = Page::create($payload);
            ActivityLog::record('page.created', "Sayfa #{$page->id} ({$slug}) oluşturuldu", auth()->id(), $page);
            session()->flash('success_message', 'Sayfa kaydedildi.');
        }

        $this->resetPageForm();
    }

    public function deletePage(int $id): void
    {
        if (! auth()->user()?->can('manage cms')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        Page::query()->whereKey($id)->delete();
        ActivityLog::record('page.deleted', "Sayfa #{$id} silindi", auth()->id());
        if ($this->pageId === $id) {
            $this->resetPageForm();
        }
        session()->flash('success_message', 'Sayfa silindi.');
    }

    public function with(): array
    {
        return [
            'textKeys' => self::TEXT_KEYS,
            'contractKeys' => self::CONTRACT_KEYS,
            'faqs' => $this->activeTab === 'faqs' ? Faq::query()->orderBy('order_num')->orderBy('id')->paginate(15) : null,
            'pages' => $this->activeTab === 'pages' ? Page::query()->latest('id')->paginate(15) : null,
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['texts' => 'Site metinleri', 'contracts' => 'Sözleşmeler', 'faqs' => 'Sıkça sorulan sorular', 'pages' => 'Sayfalar'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">İçerik ve CMS</h1>
        <p class="page-subtitle">Kaydedilen değerler ön yüzde en geç 5 dakika içinde (önbellek tazelenince) görünür.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl overflow-x-auto">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')" class="flex-none sm:flex-1 whitespace-nowrap px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === $key ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($activeTab === 'texts')
        <form wire:submit="saveTexts" class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($textKeys as $key => $label)
                    <div class="{{ in_array($key, ['slider_owner_desc', 'slider_driver_desc', 'hakkimizda_ozet'], true) ? 'md:col-span-2' : '' }}">
                        <label class="form-label">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
                        @if(in_array($key, ['slider_owner_desc', 'slider_driver_desc', 'hakkimizda_ozet'], true))
                            <textarea wire:model="texts.{{ $key }}" rows="3" class="{{ $input }}"></textarea>
                        @else
                            <input type="text" wire:model="texts.{{ $key }}" class="{{ $input }}">
                        @endif
                        @error('texts.'.$key) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Metinleri kaydet</button>
        </form>
    @endif

    @if($activeTab === 'contracts')
        <form wire:submit="saveContracts" class="apple-glass rounded-3xl p-6 space-y-5 text-xs">
            <p class="text-[11px] text-neutral-400">HTML olarak saklanır ve ön yüzde olduğu gibi basılır. Kaydederken script, iframe, object, embed, style etiketleri, on* öznitelikleri ve javascript: adresleri kaldırılır.</p>
            @foreach($contractKeys as $key => $label)
                <div>
                    <label class="form-label">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
                    <textarea wire:model="contracts.{{ $key }}" rows="10" class="{{ $input }} font-mono"></textarea>
                    @error('contracts.'.$key) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
            @endforeach
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Sözleşmeleri kaydet</button>
        </form>
    @endif

    @if($activeTab === 'faqs')
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="saveFaq" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">{{ $faqId ? 'Soruyu düzenle' : 'Yeni soru' }}</h2>
                <div>
                    <label class="form-label">Soru</label>
                    <input type="text" wire:model="faqQuestion" class="{{ $input }}">
                    @error('faqQuestion') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">Yanıt</label>
                    <textarea wire:model="faqAnswer" rows="5" class="{{ $input }}"></textarea>
                    @error('faqAnswer') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="form-label">Sıra</label>
                        <input type="number" min="0" wire:model="faqOrder" class="{{ $input }}">
                        @error('faqOrder') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                    <label class="flex items-center gap-2 mt-5"><input type="checkbox" wire:model="faqActive"> Yayında</label>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">{{ $faqId ? 'Kaydet' : 'Ekle' }}</button>
                    @if($faqId)
                        <button type="button" wire:click="resetFaq" class="btn-apple-secondary py-2.5 px-5 text-xs">Vazgeç</button>
                    @endif
                </div>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="table-cards w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Sıra</th>
                                <th class="p-4">Soru</th>
                                <th class="p-4">Durum</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($faqs as $faq)
                                <tr>
                                    <td class="p-4" data-label="Sıra">{{ $faq->order_num }}</td>
                                    <td class="p-4"><div class="font-semibold">{{ $faq->question }}</div><x-clamp-text :text="$faq->answer" lines="2" class="text-[11px] text-neutral-400" /></td>
                                    <td class="p-4" data-label="Durum"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $faq->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500' }}">{{ $faq->is_active ? 'Yayında' : 'Gizli' }}</span></td>
                                    <td class="p-4 whitespace-nowrap space-x-2 tc-actions">
                                        <button type="button" wire:click="editFaq({{ $faq->id }})" class="text-brand-500 font-semibold">Düzenle</button>
                                        <button type="button" wire:click="toggleFaq({{ $faq->id }})" class="text-neutral-500 font-semibold">{{ $faq->is_active ? 'Gizle' : 'Yayınla' }}</button>
                                        <button type="button" wire:click="deleteFaq({{ $faq->id }})" wire:confirm="Soru silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-10 text-center text-neutral-500">Henüz soru eklenmedi.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $faqs->links() }}</div>
            </div>
        </div>
    @endif

    @if($activeTab === 'pages')
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="savePage" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">{{ $pageId ? 'Sayfayı düzenle' : 'Yeni sayfa' }}</h2>
                <div>
                    <label class="form-label">Başlık</label>
                    <input type="text" wire:model="pageTitle" class="{{ $input }}">
                    @error('pageTitle') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">Kısa ad (slug, boş bırakılırsa başlıktan üretilir)</label>
                    <input type="text" wire:model="pageSlug" class="{{ $input }} font-mono">
                    @error('pageSlug') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">İçerik (HTML)</label>
                    <textarea wire:model="pageContent" rows="10" class="{{ $input }} font-mono"></textarea>
                    @error('pageContent') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="form-label">Durum</label>
                        <select wire:model="pageStatus" class="{{ $input }}">
                            <option value="draft">Taslak</option>
                            <option value="published">Yayınlandı</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 mt-5"><input type="checkbox" wire:model="pageActive"> Aktif</label>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">{{ $pageId ? 'Kaydet' : 'Oluştur' }}</button>
                    @if($pageId)
                        <button type="button" wire:click="resetPageForm" class="btn-apple-secondary py-2.5 px-5 text-xs">Vazgeç</button>
                    @endif
                </div>
                <p class="text-[11px] text-neutral-400">Sayfalar için herkese açık bir rota bu sürümde tanımlı değildir; içerik saklanır ve yayın rotası eklendiğinde kısa ad ile sunulur.</p>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="table-cards w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Başlık</th>
                                <th class="p-4">Kısa ad</th>
                                <th class="p-4">Durum</th>
                                <th class="p-4">Yayın tarihi</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($pages as $page)
                                <tr>
                                    <td class="p-4 font-semibold">{{ $page->title }}</td>
                                    <td class="p-4 font-mono" data-label="Kısa ad">{{ $page->slug }}</td>
                                    <td class="p-4" data-label="Durum"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $page->status === 'published' && $page->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500' }}">{{ $page->status === 'published' ? 'Yayınlandı' : 'Taslak' }}{{ $page->is_active ? '' : ' · pasif' }}</span></td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500" data-label="Yayın tarihi">{{ $page->published_at ? \Illuminate\Support\Carbon::parse($page->published_at)->format('d.m.Y H:i') : '—' }}</td>
                                    <td class="p-4 whitespace-nowrap space-x-2 tc-actions">
                                        <button type="button" wire:click="editPage({{ $page->id }})" class="text-brand-500 font-semibold">Düzenle</button>
                                        <button type="button" wire:click="deletePage({{ $page->id }})" wire:confirm="Sayfa silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-10 text-center text-neutral-500">Henüz sayfa oluşturulmadı.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $pages->links() }}</div>
            </div>
        </div>
    @endif
</div>
