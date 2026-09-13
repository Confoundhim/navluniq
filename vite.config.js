import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: [
                'resources/views/**',
                'app/Livewire/**',
                'routes/**',
            ],
        }),
    ],
    server: {
        // Windows IPv6 localhost çakışmasını önlemek için
        // Vite sunucusunu doğrudan 127.0.0.1 (IPv4) üzerinde dinlemeye zorluyoruz.
        host: '127.0.0.1',
        port: 5173,
    }
});
