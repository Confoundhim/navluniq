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

document.addEventListener('alpine:init', () => {
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
document.addEventListener('livewire:navigated', () => applyTheme(preferredTheme()));
document.addEventListener('livewire:navigating', () => applyTheme(preferredTheme()));
