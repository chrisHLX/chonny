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
            },
            colors: {
                surface: {
                    0: '#0F0F11',
                    1: '#141417',
                    2: '#1C1C1F',
                    3: '#242428',
                },
                ink: {
                    DEFAULT: '#E8E8E9',
                    muted: '#8D8D91',
                    subtle: '#5C5C61',
                },
                accent: {
                    DEFAULT: '#5E6AD2',
                    hover: '#6872DB',
                    muted: '#2D3170',
                },
                line: {
                    DEFAULT: '#242428',
                    strong: '#35353A',
                },
            },
        },
    },
    plugins: [forms, typography],
};
