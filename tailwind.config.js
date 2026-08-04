import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Sora', ...defaultTheme.fontFamily.sans],
                display: ['Syne', 'Sora', ...defaultTheme.fontFamily.sans],
            },
            letterSpacing: {
                tighter: '-0.04em',
                tight: '-0.02em',
            },
            colors: {
                // Ember — warm signal color (not blue SaaS, not purple)
                brand: {
                    50: '#fffbeb',
                    100: '#fef3c7',
                    200: '#fde68a',
                    300: '#fcd34d',
                    400: '#fbbf24',
                    500: '#f59e0b',
                    600: '#d97706',
                    700: '#b45309',
                    800: '#92400e',
                    900: '#78350f',
                    950: '#451a03',
                },
                ink: {
                    50: '#f6f5f4',
                    100: '#e8e6e3',
                    200: '#d2cec8',
                    300: '#b3ada4',
                    400: '#8f877c',
                    500: '#746c62',
                    600: '#5f574f',
                    700: '#4e4842',
                    800: '#433e3a',
                    900: '#1c1917',
                    950: '#0c0a09',
                },
            },
            boxShadow: {
                card: '0 1px 0 rgb(28 25 23 / 0.04), 0 8px 24px -12px rgb(28 25 23 / 0.12)',
                'card-hover': '0 1px 0 rgb(28 25 23 / 0.06), 0 20px 40px -16px rgb(28 25 23 / 0.2)',
                glow: '0 0 0 1px rgb(217 119 6 / 0.25), 0 10px 28px -8px rgb(217 119 6 / 0.45)',
                rail: 'inset 3px 0 0 0 rgb(245 158 11)',
            },
            borderRadius: {
                '2xl': '0.875rem',
                '3xl': '1.125rem',
            },
            backgroundImage: {
                'grid-ink':
                    'linear-gradient(to right, rgb(28 25 23 / 0.04) 1px, transparent 1px), linear-gradient(to bottom, rgb(28 25 23 / 0.04) 1px, transparent 1px)',
                'grid-ink-dark':
                    'linear-gradient(to right, rgb(255 255 255 / 0.04) 1px, transparent 1px), linear-gradient(to bottom, rgb(255 255 255 / 0.04) 1px, transparent 1px)',
            },
            backgroundSize: {
                grid: '48px 48px',
            },
            animation: {
                'fade-in': 'fadeIn 0.4s ease-out forwards',
                'slide-up': 'slideUp 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%': { opacity: '0', transform: 'translateY(12px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },

    plugins: [forms],
};
