import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Livewire/**/*.php',
    ],
    darkMode: 'class', // Koyu tema geçiş desteği
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#fff7ed',
                    100: '#ffedd5',
                    200: '#fed7aa',
                    300: '#fdba74',
                    400: '#fb923c',
                    500: '#f97316', // Lojistik Turuncusu
                    600: '#ea580c',
                    700: '#c2410c',
                    800: '#9a3412',
                    900: '#7c2d12',
                    950: '#431407',
                },
                neutral: {
                    50: '#fafafa',
                    100: '#f5f5f7', // Apple Açık Gri Arka Planı
                    200: '#e5e5ea',
                    300: '#d1d1d6',
                    400: '#a1a1aa',
                    500: '#737373',
                    600: '#525252',
                    700: '#3f3f46',
                    800: '#1c1c1e', // Apple Koyu Kart Rengi
                    900: '#121212', // Apple Koyu Arka Planı
                    950: '#0a0a0c',
                },
            },
            fontFamily: {
                // 🚀 GÜNCELLEME: Inter fontunu en başa ekleyerek TL sembolünün
                // her cihazda kusursuz çizilmesini sağlıyoruz.
                sans: [
                    'Inter',
                    '-apple-system',
                    'BlinkMacSystemFont',
                    'system-ui',
                    ...defaultTheme.fontFamily.sans,
                ],
            },
            boxShadow: {
                'apple-sm': '0 1px 2px rgba(0, 0, 0, 0.02), 0 1px 3px rgba(0, 0, 0, 0.01)',
                'apple-md': '0 4px 12px rgba(0, 0, 0, 0.03), 0 1px 3px rgba(0, 0, 0, 0.02)',
                'apple-lg': '0 12px 30px rgba(0, 0, 0, 0.04), 0 4px 12px rgba(0, 0, 0, 0.02)',
                'apple-dark': '0 10px 30px rgba(0, 0, 0, 0.5), 0 1px 8px rgba(255, 255, 255, 0.03)',
            },
            backdropBlur: {
                'apple': '20px',
            },
            transitionTimingFunction: {
                'apple-ease': 'cubic-bezier(0.25, 1, 0.5, 1)',
            },
            animation: {
                'fade-in': 'fadeIn 0.3s cubic-bezier(0.25, 1, 0.5, 1) forwards',
                'slide-up': 'slideUp 0.4s cubic-bezier(0.25, 1, 0.5, 1) forwards',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%': { transform: 'translateY(12px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' },
                },
            },
        },
    },
    plugins: [],
};
