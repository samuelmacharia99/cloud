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
                sans: ['"Instrument Sans"', ...defaultTheme.fontFamily.sans],
                display: ['Outfit', '"Instrument Sans"', ...defaultTheme.fontFamily.sans],
            },
            letterSpacing: {
                tighter: '-0.03em',
                tight: '-0.02em',
            },
            colors: {
                // Talksasa brand — cyan-teal (distinct from generic SaaS blue / purple)
                brand: {
                    50: '#ecfeff',
                    100: '#cffafe',
                    200: '#a5f3fc',
                    300: '#67e8f9',
                    400: '#22d3ee',
                    500: '#06b6d4',
                    600: '#0891b2',
                    700: '#0e7490',
                    800: '#155e75',
                    900: '#164e63',
                    950: '#083344',
                },
            },
            boxShadow: {
                card: '0 1px 2px rgb(15 23 42 / 0.04), 0 0 0 1px rgb(15 23 42 / 0.03)',
                'card-hover': '0 12px 32px -8px rgb(15 23 42 / 0.12), 0 0 0 1px rgb(15 23 42 / 0.04)',
                glow: '0 0 0 1px rgb(8 145 178 / 0.2), 0 8px 24px -6px rgb(8 145 178 / 0.35)',
                'inner-glow': 'inset 0 1px 0 0 rgb(255 255 255 / 0.06)',
            },
            borderRadius: {
                '2xl': '1rem',
                '3xl': '1.25rem',
            },
            animation: {
                'fade-in': 'fadeIn 0.4s ease-out forwards',
                'slide-up': 'slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%': { opacity: '0', transform: 'translateY(10px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },

    plugins: [forms],
};
