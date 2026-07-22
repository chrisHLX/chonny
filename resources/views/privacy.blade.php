<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — MindCollector</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
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

            <h1 class="font-display text-3xl sm:text-4xl font-bold text-ink mb-2">Privacy Policy</h1>
            <p class="text-[12px] text-gold font-semibold uppercase tracking-wide mb-10">Version 1.0 — July 21, 2026</p>

            <div class="linear-card p-8 space-y-8 text-[14px] text-ink-muted leading-relaxed">

                <p>
                    This Privacy Policy explains what information MindCollector collects, why we collect it, and how
                    it's used — including how it's processed by AI. It's written in plain language on purpose; if
                    anything is unclear, ask us (see Contact, below).
                </p>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">1. Information We Collect</h2>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Account information</h3>
                    <p>Your name, email address, and password (stored hashed, never in plain text) when you register.</p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Self-reported context</h3>
                    <p>
                        During the diagnostic and elsewhere, you may tell us things about how you play — your current
                        skill rating or rank, your primary role or goal, self-assessed weaknesses, and your declared
                        class/race/role/spec for a given game. This is information you actively choose to tell us; it
                        is never inferred.
                    </p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Behavioral diagnostic answers &amp; derived profile</h3>
                    <p>
                        Your answers to diagnostic scenario questions, the trait scores calculated from them, and the
                        resulting AI-generated player profile — including your archetype, summary, strengths, growth
                        areas, and the evidence used to justify each of those.
                    </p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Quiz &amp; mastery history</h3>
                    <p>
                        Which questions you've answered, whether you got them right, how many attempts it took, and
                        the mastery levels this produces per concept, skill area, and topic over time. Also, which
                        modules you've enrolled in and completed.
                    </p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Reflections</h3>
                    <p>
                        If you report back on a practice task ("what did you try, how did it go"), we store that
                        reflection along with an AI-generated interpretation of it, used to inform what we suggest you
                        practice next.
                    </p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Flagged questions</h3>
                    <p>
                        Questions you personally flag as notable while taking a quiz, and any AI explanation you
                        request for them.
                    </p>

                    <h3 class="text-[13px] font-semibold text-ink mt-4 mb-1">Session &amp; guest data</h3>
                    <p>
                        We use standard session cookies to keep you signed in and to track basic technical metadata
                        (like IP address and browser user agent) for security purposes — this is normal web
                        infrastructure, not used to build a profile of you. If you complete the diagnostic before
                        creating an account, your answers and results are held temporarily in your browser session so
                        they can be transferred into your account if you sign up; if you never sign up, this data
                        expires with the session and is not otherwise retained. We also log a small number of
                        anonymous product-usage events (e.g. whether a profile screen was viewed) to understand
                        whether features are working, not to identify you individually before you have an account.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">2. How We Use This Information</h2>
                    <p>We use the information above to:</p>
                    <ul class="list-disc list-outside ml-5 mt-2 space-y-1">
                        <li>Generate your player profile and keep it up to date as you play and reflect;</li>
                        <li>Track mastery and pick what to recommend you practice next;</li>
                        <li>Operate your account (login, credits, saved progress);</li>
                        <li>Understand, in aggregate, whether the product is working and where it isn't.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">3. AI Processing (OpenAI &amp; Gemini)</h2>
                    <p>
                        Generating your profile, recommendations, and explanations requires sending some of the data
                        above — diagnostic answers, self-reported context, mastery/concept data, and reflections — to
                        third-party AI providers: OpenAI's API and Google's Gemini API. We do not send your password
                        or email to these providers as part of this processing. As of the time of writing, both
                        providers' API terms state that data submitted through their APIs is not used to train their
                        general-purpose models — but this policy is set by them, not us, and can change; we encourage
                        you to review their own terms if this matters to you.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">4. How We Store &amp; Protect Data</h2>
                    <p>
                        Your data is stored in our database and cache infrastructure with reasonable technical
                        safeguards. No method of storage or transmission is perfectly secure, and we can't guarantee
                        absolute security.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">5. Data Sharing</h2>
                    <p>
                        We do not sell your personal information. We share it only with the AI subprocessors listed
                        above, to the extent needed to provide the Service, and if required to comply with the law.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">6. Data Retention</h2>
                    <p>
                        We keep your account data for as long as your account is active. Guest (pre-signup) data is
                        temporary, as described above. You can request deletion of your account and associated data
                        at any time (see Contact, below).
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">7. Your Choices</h2>
                    <p>
                        Declared context (class/race/role/rating/goals) can be changed or updated by you at any time.
                        You can decline to answer optional self-report questions — this only means recommendations
                        will be less personalized, nothing else. You can request a copy or deletion of your data by
                        contacting us.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">8. Children's Privacy</h2>
                    <p>
                        MindCollector is not directed at children under 13, and we do not knowingly collect personal
                        information from anyone under 13.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">9. Changes to This Policy</h2>
                    <p>
                        If we make a material change to how we handle your data, we'll update the version number and
                        date at the top of this page.
                    </p>
                </section>

                <section>
                    <h2 class="font-display text-[17px] font-semibold text-ink mb-2">10. Contact</h2>
                    <p>
                        Questions about this policy, or want to request your data or deletion? Reach us through our
                        <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="text-gold hover:text-gold-light transition-colors">Discord community</a>.
                    </p>
                </section>

            </div>

            <p class="text-[12px] text-ink-subtle text-center mt-8">
                See also our <a href="{{ route('terms') }}" class="text-gold hover:text-gold-light transition-colors">Terms of Service</a>.
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
