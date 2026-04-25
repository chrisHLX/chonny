<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mindcollector</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-0 text-ink min-h-screen flex flex-col">

    <div class="flex-1 flex flex-col items-center justify-center px-6 py-20">

        {{-- Hero --}}
        <div class="text-center max-w-2xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-accent/10 border border-accent/20 mb-8">
                <span class="w-1.5 h-1.5 rounded-full bg-accent"></span>
                <span class="text-[12px] font-medium text-accent">AI-powered adaptive learning</span>
            </div>

            <h1 class="text-5xl sm:text-6xl font-semibold text-ink tracking-tight mb-5">
                Mindcollector
            </h1>
            <p class="text-[16px] sm:text-[18px] text-ink-muted leading-relaxed mb-10">
                A new way to learn, train, and master complex concepts.<br>
                Collect knowledge as cards, build proficiency, and evolve your understanding.
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center justify-center px-5 py-2.5 text-[14px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                    Start Collecting
                </a>
                <a href="{{ route('login') }}"
                   class="inline-flex items-center justify-center px-5 py-2.5 text-[14px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                    Log In
                </a>
            </div>
        </div>

        {{-- Features --}}
        <div class="mt-20 grid gap-4 sm:grid-cols-3 max-w-4xl w-full">
            <div class="linear-card p-6">
                <div class="w-8 h-8 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
                <h3 class="text-[14px] font-semibold text-ink mb-2">Concept Cards</h3>
                <p class="text-[13px] text-ink-muted leading-relaxed">Every idea becomes a collectible card. Concepts are broken down, ranked, and trained individually.</p>
            </div>

            <div class="linear-card p-6">
                <div class="w-8 h-8 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
                <h3 class="text-[14px] font-semibold text-ink mb-2">Proficiency Progression</h3>
                <p class="text-[13px] text-ink-muted leading-relaxed">Improve understanding through multiple proficiency tiers. Track your mastery over time.</p>
            </div>

            <div class="linear-card p-6">
                <div class="w-8 h-8 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"/>
                    </svg>
                </div>
                <h3 class="text-[14px] font-semibold text-ink mb-2">Targeted Training</h3>
                <p class="text-[13px] text-ink-muted leading-relaxed">Weak areas surface automatically. Modules and quizzes arise to target exactly where you need work.</p>
            </div>
        </div>

    </div>

    {{-- Footer --}}
    <div class="border-t border-line px-6 py-4 text-center">
        <p class="text-[12px] text-ink-subtle">Mindcollector &copy; {{ date('Y') }}</p>
    </div>

</body>
</html>
