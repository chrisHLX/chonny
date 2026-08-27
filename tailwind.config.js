import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                // Headings: Archivo Black — heavy, squared grotesque for a strong
                // poster-style headline. 'Archivo' (600–900) is the fallback for
                // weights below black / before the webfont loads. Tracking is
                // tightened slightly in `.font-display` (resources/css/app.css).
                // Was Playfair Display.
                display: ['Archivo Black', 'Archivo', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Surfaces — deeper blue-blacks from the MindCollector palette
                surface: {
                    0: '#09090D',
                    1: '#111116',
                    2: '#18181E',
                    3: '#1E1E26',
                },
                // Ink — slightly brighter primary, same muted/subtle hierarchy
                ink: {
                    DEFAULT: '#F0F0F2',
                    muted: '#8A8A9A',
                    subtle: '#52525F',
                },
                // Gold — primary brand / CTA (replaces blue accent)
                gold: {
                    DEFAULT: '#C8952C',
                    light: '#E8B84B',
                    dark: '#8B6420',
                    muted: '#6B4E1A',
                    subtle: '#1E150A',
                },
                // Violet — secondary interactive / quiz elements
                violet: {
                    DEFAULT: '#7B6EE8',
                    hover: '#8B7EF8',
                    muted: '#4A3FA8',
                    subtle: '#18163A',
                },
                // accent aliased to gold so existing blade classes don't break
                accent: {
                    DEFAULT: '#C8952C',
                    hover: '#E8B84B',
                    muted: '#6B4E1A',
                },
                // Lines — slightly cooler/darker than before
                line: {
                    DEFAULT: '#1E1E26',
                    strong: '#2C2C38',
                    gold: '#6B4E1A',
                },
            },
            backgroundImage: {
                'gold-gradient': 'linear-gradient(135deg, #C8952C 0%, #E8B84B 50%, #F5D070 100%)',
                'gold-gradient-v': 'linear-gradient(180deg, #E8B84B 0%, #C8952C 100%)',
                'violet-gradient': 'linear-gradient(135deg, #6B5BE6 0%, #8B7EF8 100%)',
            },
            boxShadow: {
                'gold-sm': '0 0 12px rgba(200, 149, 44, 0.25)',
                'gold': '0 0 24px rgba(200, 149, 44, 0.35)',
                'gold-lg': '0 0 48px rgba(200, 149, 44, 0.45)',
                'violet-sm': '0 0 12px rgba(123, 110, 232, 0.25)',
                'violet': '0 0 24px rgba(123, 110, 232, 0.35)',
            },
        },
    },
    plugins: [forms, typography],
};
