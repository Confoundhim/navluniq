import './bootstrap';
import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Harita kütüphanesi bileşenlerin inline script'lerinden erişilebilsin diye globale alınır.
// Vite paketlemesinde varsayılan işaretçi görselleri bulunamaz; açıkça tanımlanır.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({ iconRetinaUrl: markerIcon2x, iconUrl: markerIcon, shadowUrl: markerShadow });
window.L = L;

const THEME_KEY = 'theme';

function preferredTheme() {
    try {
        const stored = localStorage.getItem(THEME_KEY);
        if (stored === 'dark' || stored === 'light') return stored;
    } catch (e) {}
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#121212' : '#f97316');
}

const TEXT_SIZE_KEY = 'textSize';

function preferredTextSize() {
    try { return localStorage.getItem(TEXT_SIZE_KEY) === 'large' ? 'large' : 'normal'; } catch (e) { return 'normal'; }
}

function applyTextSize(size) {
    document.documentElement.classList.toggle('text-large', size === 'large');
}

document.addEventListener('alpine:init', () => {
    // Büyük yazı tercihi: kök yazı boyutu büyütülür, rem tabanlı tüm ölçüler orantılı büyür.
    Alpine.store('textSize', {
        large: preferredTextSize() === 'large',

        toggle() {
            this.set(this.large ? 'normal' : 'large');
        },

        set(size) {
            this.large = size === 'large';
            try { localStorage.setItem(TEXT_SIZE_KEY, size); } catch (e) {}
            applyTextSize(size);
        },

        init() {
            applyTextSize(this.large ? 'large' : 'normal');
        }
    });

    Alpine.store('darkMode', {
        on: preferredTheme() === 'dark',

        toggle() {
            this.set(this.on ? 'light' : 'dark');
        },

        set(theme) {
            this.on = theme === 'dark';
            try { localStorage.setItem(THEME_KEY, theme); } catch (e) {}
            applyTheme(theme);
        },

        init() {
            applyTheme(this.on ? 'dark' : 'light');
        }
    });
});

// wire:navigate ile sayfa değişince <html> sınıfları yeni sayfadan gelir; temayı yeniden uygula.
document.addEventListener('livewire:navigated', () => { applyTheme(preferredTheme()); applyTextSize(preferredTextSize()); });

// Süreli yenileme (wire:poll) kullanıcıyı rahatsız etmesin: son etkileşimden bu yana geçen süre her
// Livewire isteğine başlık olarak eklenir; sunucu kısa süre içinde etkileşim varsa yenilemeyi çizmez
// (App\Livewire\PausePollWhileInteracting). Odaklı bir form alanı varsa kullanıcı hâlâ meşguldür.
let lastInteraction = 0;
const touch = () => { lastInteraction = Date.now(); };
['pointerdown', 'pointermove', 'keydown', 'input', 'touchstart', 'wheel', 'scroll'].forEach((ev) =>
    document.addEventListener(ev, touch, { capture: true, passive: true }));
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ options }) => {
        if (! options) return;
        options.headers = options.headers || {};
        const el = document.activeElement;
        const focused = el && ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) && el.type !== 'hidden';
        options.headers['X-User-Idle-Ms'] = String(focused ? 0 : Date.now() - lastInteraction);
    });
});
document.addEventListener('livewire:navigating', () => { applyTheme(preferredTheme()); applyTextSize(preferredTextSize()); });


// Göreli zaman etiketleri ("az önce", "3 dk önce") tarayıcıda kendi kendine ilerler; ekranın yeniden
// çizilmesine bağlı değildir. Kural App\Support\TimeAgo ile aynıdır (saniye gösterilmez).
function agoLabel(ts) {
    const diff = Math.round(Date.now() / 1000) - ts;
    const future = diff < 0, s = Math.abs(diff), suffix = future ? ' sonra' : ' önce';
    if (s < 60) return future ? 'birazdan' : 'az önce';
    if (s < 3600) return Math.floor(s / 60) + ' dk' + suffix;
    if (s < 86400) return Math.floor(s / 3600) + ' sa' + suffix;
    const days = Math.floor(s / 86400);
    if (days === 1) return future ? 'yarın' : 'dün';
    if (days < 30) return days + ' gün' + suffix;
    const d = new Date(ts * 1000), p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear();
}
function tickAgo() {
    document.querySelectorAll('[data-ago]').forEach((el) => {
        const ts = parseInt(el.getAttribute('data-ago'), 10);
        if (!Number.isFinite(ts)) return;
        const label = agoLabel(ts);
        if (el.textContent !== label) el.textContent = label;
    });
}
setInterval(tickAgo, 15000);
document.addEventListener('DOMContentLoaded', tickAgo);
document.addEventListener('livewire:navigated', tickAgo);
