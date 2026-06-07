<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MindCollector — Learn How Top Players Think</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-0 text-ink min-h-screen flex flex-col">

    {{-- Minimal nav bar --}}
    <header class="border-b border-line px-6 py-3 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2.5">
            <div class="w-5 h-5 rounded bg-accent flex items-center justify-center shrink-0">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
            </div>
            <span class="text-[13px] font-semibold text-ink tracking-tight">MindCollector</span>
        </div>
        <div class="flex items-center gap-2">
            @auth
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center px-3 py-1.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                Go to Dashboard
            </a>
            @else
            <a href="{{ route('login') }}"
               class="inline-flex items-center px-3 py-1.5 text-[13px] font-medium text-ink-muted hover:text-ink transition-colors">
                Log In
            </a>
            <a href="{{ route('register') }}"
               class="inline-flex items-center px-3 py-1.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                Sign Up Free
            </a>
            @endauth
        </div>
    </header>

    <div class="flex-1 flex flex-col items-center px-6 py-16">

        {{-- Hero --}}
        <div class="text-center max-w-2xl w-full">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-accent/10 border border-accent/20 mb-8">
                <span class="w-1.5 h-1.5 rounded-full bg-accent"></span>
                <span class="text-[12px] font-medium text-accent">Gaming adaptive learning</span>
            </div>

            <h1 class="text-5xl sm:text-6xl font-semibold text-ink tracking-tight mb-5">
                Learn How Top<br>Players Think
            </h1>
            <p class="text-[16px] sm:text-[17px] text-ink-muted leading-relaxed mb-10">
                Interactive guides for WoW, StarCraft II, and League of Legends.<br>
                Train your knowledge, discover weaknesses, and build expert-level instincts.
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="{{ route('modules.index') }}"
                   class="inline-flex items-center justify-center px-5 py-2.5 text-[14px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                    Browse Guides
                </a>
                @guest
                <a href="{{ route('register') }}"
                   class="inline-flex items-center justify-center px-5 py-2.5 text-[14px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                    Sign Up Free
                </a>
                @endguest
            </div>
        </div>

        {{-- How It Works --}}
        <div class="mt-24 w-full max-w-4xl">
            <h2 class="text-[13px] font-semibold text-ink-subtle uppercase tracking-widest text-center mb-8">How It Works</h2>
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="linear-card p-5 text-center">
                    <div class="w-7 h-7 rounded-full bg-accent/10 border border-accent/20 flex items-center justify-center mx-auto mb-3">
                        <span class="text-[12px] font-bold text-accent">1</span>
                    </div>
                    <h3 class="text-[13px] font-semibold text-ink mb-1.5">Pick a Guide</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">Choose a topic — PvP rotations, macro strategy, itemisation, and more.</p>
                </div>

                <div class="linear-card p-5 text-center">
                    <div class="w-7 h-7 rounded-full bg-accent/10 border border-accent/20 flex items-center justify-center mx-auto mb-3">
                        <span class="text-[12px] font-bold text-accent">2</span>
                    </div>
                    <h3 class="text-[13px] font-semibold text-ink mb-1.5">Answer Questions</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">Work through adaptive quizzes that adjust difficulty based on your performance.</p>
                </div>

                <div class="linear-card p-5 text-center">
                    <div class="w-7 h-7 rounded-full bg-accent/10 border border-accent/20 flex items-center justify-center mx-auto mb-3">
                        <span class="text-[12px] font-bold text-accent">3</span>
                    </div>
                    <h3 class="text-[13px] font-semibold text-ink mb-1.5">Discover Weaknesses</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">The platform identifies exactly where your understanding breaks down.</p>
                </div>

                <div class="linear-card p-5 text-center">
                    <div class="w-7 h-7 rounded-full bg-accent/10 border border-accent/20 flex items-center justify-center mx-auto mb-3">
                        <span class="text-[12px] font-bold text-accent">4</span>
                    </div>
                    <h3 class="text-[13px] font-semibold text-ink mb-1.5">Get Recommendations</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">AI suggests the next guide to target your weakest topics.</p>
                </div>
            </div>
        </div>

        {{-- Featured Games --}}
        <div class="mt-16 w-full max-w-4xl">
            <h2 class="text-[13px] font-semibold text-ink-subtle uppercase tracking-widest text-center mb-8">Supported Games</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <a href="{{ route('modules.index') }}" class="linear-card p-6 hover:bg-surface-2 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-ink mb-1 group-hover:text-accent transition-colors">World of Warcraft PvP</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">Arena tactics, class mechanics, dampening, and cooldown management.</p>
                </a>

                <a href="{{ route('modules.index') }}" class="linear-card p-6 hover:bg-surface-2 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-ink mb-1 group-hover:text-accent transition-colors">StarCraft II</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">Build orders, macro cycles, scouting, and matchup-specific strategy.</p>
                </a>

                <a href="{{ route('modules.index') }}" class="linear-card p-6 hover:bg-surface-2 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-accent/10 border border-accent/20 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-ink mb-1 group-hover:text-accent transition-colors">League of Legends</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed">Wave management, vision control, champion matchups, and teamfight positioning.</p>
                </a>
            </div>
        </div>

        {{-- Alpha / Discord --}}
        <div class="mt-16 w-full max-w-2xl">
            <div class="linear-card p-8 text-center">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-accent/10 border border-accent/20 mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
                    <span class="text-[12px] font-medium text-accent">Now in alpha</span>
                </div>
                <h2 class="text-[18px] font-semibold text-ink mb-3">Help shape MindCollector</h2>
                <p class="text-[13px] text-ink-muted leading-relaxed mb-6">
                    We're looking for early testers. Join the Discord, share feedback,
                    suggest guides, and help build the platform.
                </p>
                <a href="https://discord.gg/Bk7wEvPRt"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 text-[14px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                    </svg>
                    Join Discord
                </a>
            </div>
        </div>

        {{-- Bottom CTA --}}
        @guest
        <div class="mt-16 text-center">
            <p class="text-[14px] text-ink-muted mb-4">Ready to train like a top player?</p>
            <a href="{{ route('register') }}"
               class="inline-flex items-center justify-center px-6 py-3 text-[14px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                Create a Free Account
            </a>
        </div>
        @endguest

    </div>

    {{-- Footer --}}
    <div class="border-t border-line px-6 py-4 text-center">
        <p class="text-[12px] text-ink-subtle">MindCollector &copy; {{ date('Y') }}</p>
    </div>

</body>
</html>
