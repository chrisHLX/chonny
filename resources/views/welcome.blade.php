<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MindCollector — Learn How Top Players Think</title>
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

    {{-- Hero --}}
    <section class="relative flex flex-col items-center text-center px-6 pt-24 pb-20 sm:pt-32 sm:pb-28 overflow-hidden">

        {{-- Sacred geometry background --}}
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none select-none" aria-hidden="true">
            <svg class="w-[720px] h-[720px] text-gold opacity-[0.12]" viewBox="0 0 600 600" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="300" cy="300" r="260" stroke="currentColor" stroke-width="0.75"/>
                <circle cx="300" cy="300" r="200" stroke="currentColor" stroke-width="0.75"/>
                <circle cx="300" cy="300" r="140" stroke="currentColor" stroke-width="0.75"/>
                <circle cx="300" cy="300" r="80"  stroke="currentColor" stroke-width="0.75"/>
                <polygon points="300,40 525,170 525,430 300,560 75,430 75,170"    stroke="currentColor" stroke-width="0.75" fill="none"/>
                <polygon points="300,100 473,200 473,400 300,500 127,400 127,200" stroke="currentColor" stroke-width="0.75" fill="none"/>
                <polygon points="300,160 421,230 421,370 300,440 179,370 179,230" stroke="currentColor" stroke-width="0.75" fill="none"/>
                <polygon points="300,60 92,420 508,420"  stroke="currentColor" stroke-width="0.75" fill="none"/>
                <polygon points="300,540 508,180 92,180" stroke="currentColor" stroke-width="0.75" fill="none"/>
                <line x1="300" y1="300" x2="300" y2="40"  stroke="currentColor" stroke-width="0.5"/>
                <line x1="300" y1="300" x2="525" y2="170" stroke="currentColor" stroke-width="0.5"/>
                <line x1="300" y1="300" x2="525" y2="430" stroke="currentColor" stroke-width="0.5"/>
                <line x1="300" y1="300" x2="300" y2="560" stroke="currentColor" stroke-width="0.5"/>
                <line x1="300" y1="300" x2="75"  y2="430" stroke="currentColor" stroke-width="0.5"/>
                <line x1="300" y1="300" x2="75"  y2="170" stroke="currentColor" stroke-width="0.5"/>
                <circle cx="300" cy="300" r="5" fill="currentColor" opacity="0.4"/>
            </svg>
        </div>

        {{-- Badge --}}
        <div class="relative inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet/10 border border-violet/30 mb-8">
            <span class="w-1.5 h-1.5 rounded-full bg-gold"></span>
            <span class="text-[12px] font-medium text-violet tracking-wide">Gaming Adaptive Learning</span>
        </div>

        {{-- Heading --}}
        <h1 class="relative font-display text-5xl sm:text-6xl lg:text-7xl font-bold leading-[1.1] mb-6 max-w-3xl">
            <span class="text-ink">Discover the</span><br>
            <span class="text-gold-light">Structure Beneath</span><br>
            <span class="text-ink">Performance</span>
        </h1>

        {{-- Subheading --}}
        <p class="relative text-[16px] sm:text-[17px] text-ink-muted leading-relaxed mb-10 max-w-xl">
            MindCollector maps your game knowledge, reveals hidden weaknesses,
            and guides you toward true mastery.
        </p>

        {{-- CTAs --}}
        <div class="relative flex flex-col sm:flex-row gap-3 justify-center">
            <a href="{{ route('modules.index') }}"
               class="inline-flex items-center justify-center gap-2 px-7 py-3.5 text-[15px] font-semibold
                      text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
                Explore the Map
            </a>
            @guest
            <a href="{{ route('diagnostic') }}"
               class="inline-flex items-center justify-center gap-2 px-7 py-3.5 text-[15px] font-medium
                      bg-surface-2 text-violet border border-violet/40
                      hover:bg-violet/10 hover:border-violet rounded-md transition-all duration-200">
                Take Diagnostic Quiz
            </a>
            @endguest
        </div>

    </section>

    {{-- Content sections --}}
    <div class="flex-1 flex flex-col items-center px-6 pb-16">

        {{-- Supported Games --}}
        <div class="mt-4 w-full max-w-4xl">
            <div class="flex items-center gap-4 mb-10">
                <div class="flex-1 h-px bg-gold/20"></div>
                <span class="text-[11px] font-semibold text-gold uppercase tracking-[0.2em]">Supported Games</span>
                <div class="flex-1 h-px bg-gold/20"></div>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">

                {{-- World of Warcraft — Emerald --}}
                <a href="{{ route('modules.quiz', 'arena-playstyle-assessment') }}"
                   class="rounded-lg border bg-emerald-900/20 border-emerald-500/30
                          hover:border-emerald-500/60 hover:shadow-[0_0_28px_rgba(16,185,129,0.12)]
                          p-5 block group transition-all duration-200 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-emerald-400/20"/>
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-wow" class="w-9 h-9 shrink-0 text-emerald-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-emerald-300 transition-colors leading-tight">World of Warcraft PvP</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Arena tactics, class mechanics, dampening, and cooldown management.</p>
                            <div class="flex items-center gap-1.5 text-emerald-400">
                                <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Growth</span>
                            </div>
                        </div>
                        <svg class="w-24 h-24 shrink-0" viewBox="0 0 130 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="65,15 108,40 108,90 65,115 22,90 22,40" stroke="#10b981" stroke-width="0.75" fill="none" opacity="0.18"/>
                            <polygon points="65,28 98,46 98,84 65,103 32,84 32,46" stroke="#10b981" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,40 87,53 87,78 65,90 43,78 43,53" stroke="#10b981" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,53 76,59 76,71 65,78 54,71 54,59" stroke="#10b981" stroke-width="0.75" fill="none" opacity="0.08"/>
                            <line x1="65" y1="65" x2="65"  y2="15"  stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="40"  stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="90"  stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="65"  y2="115" stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="90"  stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="40"  stroke="#10b981" stroke-width="0.5" opacity="0.18"/>
                            <circle cx="65" cy="15"  r="1.5" fill="#10b981" opacity="0.45"/>
                            <circle cx="108" cy="40" r="1.5" fill="#10b981" opacity="0.45"/>
                            <circle cx="108" cy="90" r="1.5" fill="#10b981" opacity="0.45"/>
                            <circle cx="65" cy="115" r="1.5" fill="#10b981" opacity="0.45"/>
                            <circle cx="22" cy="90"  r="1.5" fill="#10b981" opacity="0.45"/>
                            <circle cx="22" cy="40"  r="1.5" fill="#10b981" opacity="0.45"/>
                            <text x="65"  y="9"   text-anchor="middle" font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">E</text>
                            <text x="115" y="38"  text-anchor="start"  font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">X</text>
                            <text x="115" y="93"  text-anchor="start"  font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">I</text>
                            <text x="65"  y="125" text-anchor="middle" font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">D</text>
                            <text x="15"  y="93"  text-anchor="end"    font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">C</text>
                            <text x="15"  y="38"  text-anchor="end"    font-size="6.5" fill="#10b981" opacity="0.55" font-family="monospace">A</text>
                            <polygon points="65,23 82,55 71,69 65,75 39,80 27,43"
                                     fill="#10b981" fill-opacity="0.22" stroke="#10b981" stroke-width="1.5" stroke-opacity="0.8"/>
                            <circle cx="65" cy="65" r="2.5" fill="#10b981" opacity="0.7"/>
                        </svg>
                    </div>
                </a>

                {{-- StarCraft II — Sky --}}
                <a href="{{ route('modules.quiz', 'commander-profile-assessment') }}"
                   class="rounded-lg border bg-sky-900/20 border-sky-500/30
                          hover:border-sky-500/60 hover:shadow-[0_0_28px_rgba(56,189,248,0.12)]
                          p-5 block group transition-all duration-200 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-sky-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-sky-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-sky-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-sky-400/20"/>
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-sc2" class="w-9 h-9 shrink-0 text-sky-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-sky-300 transition-colors leading-tight">StarCraft II</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Build orders, macro cycles, scouting, and matchup-specific strategy.</p>
                            <div class="flex items-center gap-1.5 text-sky-400">
                                <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Strategy</span>
                            </div>
                        </div>
                        <svg class="w-24 h-24 shrink-0" viewBox="0 0 130 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="65,15 108,40 108,90 65,115 22,90 22,40" stroke="#38bdf8" stroke-width="0.75" fill="none" opacity="0.18"/>
                            <polygon points="65,28 98,46 98,84 65,103 32,84 32,46" stroke="#38bdf8" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,40 87,53 87,78 65,90 43,78 43,53" stroke="#38bdf8" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,53 76,59 76,71 65,78 54,71 54,59" stroke="#38bdf8" stroke-width="0.75" fill="none" opacity="0.08"/>
                            <line x1="65" y1="65" x2="65"  y2="15"  stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="40"  stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="90"  stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="65"  y2="115" stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="90"  stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="40"  stroke="#38bdf8" stroke-width="0.5" opacity="0.18"/>
                            <circle cx="65" cy="15"  r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <circle cx="108" cy="40" r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <circle cx="108" cy="90" r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <circle cx="65" cy="115" r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <circle cx="22" cy="90"  r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <circle cx="22" cy="40"  r="1.5" fill="#38bdf8" opacity="0.45"/>
                            <text x="65"  y="9"   text-anchor="middle" font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">E</text>
                            <text x="115" y="38"  text-anchor="start"  font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">X</text>
                            <text x="115" y="93"  text-anchor="start"  font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">I</text>
                            <text x="65"  y="125" text-anchor="middle" font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">D</text>
                            <text x="15"  y="93"  text-anchor="end"    font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">C</text>
                            <text x="15"  y="38"  text-anchor="end"    font-size="6.5" fill="#38bdf8" opacity="0.55" font-family="monospace">A</text>
                            <polygon points="65,18 74,60 78,73 65,111 54,71 57,61"
                                     fill="#38bdf8" fill-opacity="0.22" stroke="#38bdf8" stroke-width="1.5" stroke-opacity="0.8"/>
                            <circle cx="65" cy="65" r="2.5" fill="#38bdf8" opacity="0.7"/>
                        </svg>
                    </div>
                </a>

                {{-- League of Legends — Red --}}
                <a href="{{ route('modules.quiz', 'ranked-playstyle-assessment') }}"
                   class="rounded-lg border bg-red-900/20 border-red-500/30
                          hover:border-red-500/60 hover:shadow-[0_0_28px_rgba(248,113,113,0.12)]
                          p-5 block group transition-all duration-200 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-red-400/20"/>
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-lol" class="w-9 h-9 shrink-0 text-red-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-red-300 transition-colors leading-tight">League of Legends</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Wave management, vision control, champion matchups, and teamfight positioning.</p>
                            <div class="flex items-center gap-1.5 text-red-400">
                                <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Mastery</span>
                            </div>
                        </div>
                        <svg class="w-24 h-24 shrink-0" viewBox="0 0 130 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="65,15 108,40 108,90 65,115 22,90 22,40" stroke="#f87171" stroke-width="0.75" fill="none" opacity="0.18"/>
                            <polygon points="65,28 98,46 98,84 65,103 32,84 32,46" stroke="#f87171" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,40 87,53 87,78 65,90 43,78 43,53" stroke="#f87171" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,53 76,59 76,71 65,78 54,71 54,59" stroke="#f87171" stroke-width="0.75" fill="none" opacity="0.08"/>
                            <line x1="65" y1="65" x2="65"  y2="15"  stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="40"  stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="90"  stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="65"  y2="115" stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="90"  stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="40"  stroke="#f87171" stroke-width="0.5" opacity="0.18"/>
                            <circle cx="65" cy="15"  r="1.5" fill="#f87171" opacity="0.45"/>
                            <circle cx="108" cy="40" r="1.5" fill="#f87171" opacity="0.45"/>
                            <circle cx="108" cy="90" r="1.5" fill="#f87171" opacity="0.45"/>
                            <circle cx="65" cy="115" r="1.5" fill="#f87171" opacity="0.45"/>
                            <circle cx="22" cy="90"  r="1.5" fill="#f87171" opacity="0.45"/>
                            <circle cx="22" cy="40"  r="1.5" fill="#f87171" opacity="0.45"/>
                            <text x="65"  y="9"   text-anchor="middle" font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">E</text>
                            <text x="115" y="38"  text-anchor="start"  font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">X</text>
                            <text x="115" y="93"  text-anchor="start"  font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">I</text>
                            <text x="65"  y="125" text-anchor="middle" font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">D</text>
                            <text x="15"  y="93"  text-anchor="end"    font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">C</text>
                            <text x="15"  y="38"  text-anchor="end"    font-size="6.5" fill="#f87171" opacity="0.55" font-family="monospace">A</text>
                            <polygon points="65,44 104,43 103,87 65,90 57,70 54,59"
                                     fill="#f87171" fill-opacity="0.22" stroke="#f87171" stroke-width="1.5" stroke-opacity="0.8"/>
                            <circle cx="65" cy="65" r="2.5" fill="#f87171" opacity="0.7"/>
                        </svg>
                    </div>
                </a>

            </div>
        </div>

        {{-- Alpha / Discord --}}
        <div class="mt-16 w-full max-w-2xl">
            <div class="linear-card p-8 text-center">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet/10 border border-violet/30 mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-gold animate-pulse"></span>
                    <span class="text-[12px] font-medium text-violet">Now in alpha</span>
                </div>
                <h2 class="font-display text-[20px] font-semibold text-ink mb-3">Help shape MindCollector</h2>
                <p class="text-[13px] text-ink-muted leading-relaxed mb-6">
                    We're looking for early testers. Join the Discord, share feedback,
                    suggest guides, and help build the platform.
                </p>
                <a href="https://discord.gg/Bk7wEvPRt"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 text-[14px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
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
            <p class="text-[14px] text-ink-muted mb-5">Ready to train like a top player?</p>
            <a href="{{ route('register') }}"
               class="inline-flex items-center justify-center gap-2 px-7 py-3.5 text-[15px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                Create a Free Account
            </a>
        </div>
        @endguest

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
                <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer"
                   class="hover:text-gold transition-colors">Discord</a>
                <a href="{{ route('login') }}"    class="hover:text-gold transition-colors">Sign In</a>
                <a href="{{ route('register') }}" class="hover:text-gold transition-colors">Sign Up</a>
            </div>
        </div>
    </footer>

</body>
</html>
