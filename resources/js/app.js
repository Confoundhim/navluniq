// Çekirdek kütüphaneleri dahil ediyoruz
import './bootstrap';

document.addEventListener('alpine:init', () => {
    // Apple Koyu Tema (Dark Mode) Yönetimi için Global State
    Alpine.store('darkMode', {
        on: localStorage.getItem('theme') === 'dark',

        toggle() {
            this.on = !this.on;
            if (this.on) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            }
        },

        init() {
            // İlk açılışta sistem veya kullanıcı tercihini kontrol et
            if (localStorage.getItem('theme') === 'dark' ||
                (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                this.on = true;
                document.documentElement.classList.add('dark');
            } else {
                this.on = false;
                document.documentElement.classList.remove('dark');
            }
        }
    });
});
