import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // config/spell_display.php holds the category / DR-category badge maps. Those class
        // names used to be copy-pasted into the blades and so were picked up by the scan above;
        // consolidating them into a config file put them somewhere Tailwind does not look by
        // default, which silently purged `badge-orange` from the build (the only one of the
        // seven not still referenced literally in some blade) and left every Offensive category
        // badge and Knockback DR badge rendering with no styling at all. Scanned explicitly so
        // the file stays a safe place to add a badge — do not remove without moving those maps
        // back into the templates.
        './config/spell_display.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                // Headings: Inter at heavy weight with tight tracking (.font-display in
                // app.css), the way Spotify sets its titles. One family for the whole site:
                // Archivo Black was a second and third webfont on every page (2026-10-11), and
                // a serif (Marcellus) tried on 2026-10-29 read as fussy at small sizes and was
                // dropped before it shipped.
                display: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Surfaces: Spotify's neutral greys (2026-10-11), the same steps the site and
                // the desktop pages share (resources/views/desktop/partials/styles.blade.php,
                // tools/desktop-app/Theme.cs). `frame` is the black around the panels.
                frame: '#000000',
                surface: {
                    0: '#121212',
                    1: '#181818',
                    2: '#232323',
                    3: '#2A2A2A',
                },
                ink: {
                    DEFAULT: '#FFFFFF',
                    muted: '#B3B3B3',
                    subtle: '#6A6A6A',
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
                line: {
                    DEFAULT: '#282828',
                    strong: '#3E3E3E',
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
