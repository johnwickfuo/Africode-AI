import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                // Stadium-night surfaces: deeper and bluer than slate so the
                // brand green reads as floodlit rather than washed out.
                ink: {
                    950: '#060910',
                    900: '#0a0e18',
                    850: '#0e1422',
                    800: '#141c2c',
                    750: '#1a2336',
                    700: '#212c43',
                    600: '#2c3a54',
                    500: '#475874',
                    400: '#6b7c99',
                    300: '#94a3b8',
                    200: '#c8d2e0',
                    100: '#e9eef6',
                },
                brand: {
                    50: '#ecfdf3',
                    100: '#d3f8e0',
                    200: '#a6f0c4',
                    300: '#6ee7a5',
                    400: '#3ad986',
                    500: '#1cc46c',
                    600: '#0f9f56',
                    700: '#0c7b43',
                },
                value: {
                    400: '#4cc4f5',
                    500: '#1fa8e0',
                },
            },
            fontFamily: {
                sans: [
                    '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto',
                    '"Helvetica Neue"', 'Arial', ...defaultTheme.fontFamily.sans,
                ],
                mono: ['"SF Mono"', '"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            boxShadow: {
                card: '0 1px 2px rgba(0,0,0,.45), 0 12px 28px -18px rgba(0,0,0,.9)',
                lift: '0 1px 2px rgba(0,0,0,.5), 0 18px 40px -22px rgba(0,0,0,1)',
                glow: '0 0 0 1px rgba(58,217,134,.22), 0 14px 40px -16px rgba(28,196,108,.45)',
                'glow-sm': '0 8px 24px -12px rgba(28,196,108,.55)',
            },
            backgroundImage: {
                'brand-gradient': 'linear-gradient(135deg, #3ad986 0%, #0f9f56 100%)',
                'card-sheen': 'linear-gradient(180deg, rgba(255,255,255,.045) 0%, rgba(255,255,255,0) 45%)',
                'pitch-glow':
                    'radial-gradient(120% 90% at 50% -20%, rgba(28,196,108,.18) 0%, rgba(28,196,108,0) 60%)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(6px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'bar-grow': {
                    '0%': { transform: 'scaleX(0)' },
                    '100%': { transform: 'scaleX(1)' },
                },
            },
            animation: {
                'fade-up': 'fade-up .35s cubic-bezier(.22,.68,.4,1) both',
                'bar-grow': 'bar-grow .5s cubic-bezier(.22,.68,.4,1) both',
            },
        },
    },
    plugins: [],
};
