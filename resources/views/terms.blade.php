<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service — MindCollector</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-0 text-ink min-h-screen flex flex-col">

    {{-- Nav --}}
    <header class="border-b border-gold/20 px-6 flex items-center justify-between h-14 shrink-0">
        <a href="/" class="flex items-center gap-2 hover:opacity-90 transition-opacity">
            <svg class="w-7 h-7 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
            </svg>
            <span class="font-display text-[15px] font-bold tracking-wide">
                <span class="text-ink">Mind</span><span class="text-gold">Collector</span>
            </span>
        </a>
        <div class="flex items-center gap-3">
            @auth
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center px-4 py-1.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold-sm transition-all duration-200">
                Dashboard
            </a>
            @else
            <a href="{{ route('login') }}"
               class="text-[13px] font-medium text-ink-muted hover:text-ink transition-colors">
                Log In
            </a>
            <a href="{{ route('register') }}"
               class="inline-flex items-center px-4 py-1.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold-sm transition-all duration-200">
                Sign Up Free
            </a>
            @endauth
        </div>
    </header>

    {{-- Content --}}
    <div class="flex-1 flex justify-center px-6 py-16">
        <div class="w-full max-w-2xl">

            <h1 class="font-display text-3xl sm:text-4xl font-bold text-ink mb-2">Terms of Service</h1>
            <p class="text-[12px] text-gold font-semibold uppercase tracking-wide mb-10">Version 1.0 — July 21, 2026</p>

            <div class="linear-card p-8 space-y-8 text-[14px] text-ink-muted leading-relaxed">

                <p>
                    These Terms of Service ("Terms") govern your use of MindCollector (the "Service"). By creating an
                    account, you agree to these Terms. If you don't agree, please don't create an account or use the
                    Service.
                </p>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">1. What MindCollector Is</h2>
                    <p>
                        MindCollector is an adaptive learning platform for competitive games. It runs a diagnostic
                        assessment to build a profile of how you play, tracks your quiz performance and mastery over
                        time, and uses that information — together with AI — to recommend what to practice next.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">2. Accounts</h2>
                    <p>
                        You must provide a valid email address and accurate name when registering. You're responsible
                        for keeping your password secure and for all activity that happens under your account. One
                        account per person. The Service is not directed at children under 13, and you must not use it
                        if you are under the minimum age of digital consent in your country.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">3. Educational Purpose — No Guaranteed Outcomes</h2>
                    <p>
                        MindCollector is an educational and training tool, not professional coaching, and nothing on
                        the platform is a promise or guarantee that your rank, rating, or skill will improve. Player
                        profiles, recommendations, and explanations are generated in part by AI models and may be
                        incomplete, generic, or occasionally inaccurate — use your own judgment when applying them to
                        your own play. You are solely responsible for the decisions you make in-game based on
                        anything you see here.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">4. Acceptable Use</h2>
                    <p>You agree not to:</p>
                    <ul class="list-disc list-outside ml-5 mt-2 space-y-1">
                        <li>Use the Service to harass, abuse, or harm another person;</li>
                        <li>Attempt to bypass, exploit, or manipulate the diagnostic, scoring, or credit systems;</li>
                        <li>Scrape, reverse-engineer, or automate access to the Service outside its intended UI;</li>
                        <li>Use the Service for any unlawful purpose.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">5. Suspension &amp; Termination</h2>
                    <p>
                        We may suspend or terminate your account, with or without notice, if we reasonably believe
                        you've violated these Terms or misused the Service. You may stop using the Service and
                        request account deletion at any time (see the Privacy Policy for how to request this).
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">6. The Service "As Is"</h2>
                    <p>
                        The Service, including all AI-generated content, is provided "as is" and "as available"
                        without warranties of any kind, whether express or implied, including as to accuracy,
                        reliability, or uninterrupted availability. To the fullest extent permitted by law,
                        MindCollector is not liable for indirect, incidental, or consequential damages arising from
                        your use of the Service.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">7. Changes to These Terms</h2>
                    <p>
                        We may update these Terms as the product changes. If we make a material change, we'll update
                        the version number and date at the top of this page. Continuing to use the Service after an
                        update means you accept the revised Terms.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">8. Contact</h2>
                    <p>
                        Questions about these Terms? Reach us through our
                        <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="text-gold hover:text-gold-light transition-colors">Discord community</a>.
                    </p>
                </section>

            </div>

            <p class="text-[12px] text-ink-subtle text-center mt-8">
                See also our <a href="{{ route('privacy') }}" class="text-gold hover:text-gold-light transition-colors">Privacy Policy</a>.
            </p>

        </div>
    </div>

    {{-- Footer --}}
    <footer class="border-t border-line px-6 py-5 mt-auto">
        <div class="max-w-4xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                    <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
                </svg>
                <span class="text-[12px] text-ink-subtle">
                    <span class="text-ink-muted">Mind</span><span class="text-gold">Collector</span>
                    &copy; {{ date('Y') }}
                </span>
            </div>
            <div class="flex items-center gap-5 text-[12px] text-ink-subtle">
                <a href="{{ route('terms') }}" class="hover:text-gold transition-colors">Terms</a>
                <a href="{{ route('privacy') }}" class="hover:text-gold transition-colors">Privacy</a>
                <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="hover:text-gold transition-colors">Discord</a>
                <a href="{{ route('login') }}" class="hover:text-gold transition-colors">Sign In</a>
                <a href="{{ route('register') }}" class="hover:text-gold transition-colors">Sign Up</a>
            </div>
        </div>
    </footer>

</body>
</html>
