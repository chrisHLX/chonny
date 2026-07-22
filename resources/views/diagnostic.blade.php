<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Quiz — MindCollector</title>
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

    {{-- Hero + game picker --}}
    <section class="relative flex flex-col items-center text-center px-6 pt-20 pb-8 overflow-hidden">

        {{-- Sacred geometry background --}}
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none select-none" aria-hidden="true">
            <svg class="w-[600px] h-[600px] text-gold opacity-[0.09]" viewBox="0 0 600 600" fill="none" xmlns="http://www.w3.org/2000/svg">
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
        <div class="relative inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet/10 border border-violet/30 mb-6">
            <span class="w-1.5 h-1.5 rounded-full bg-gold animate-pulse"></span>
            <span class="text-[12px] font-medium text-violet tracking-wide">Diagnostic Quiz</span>
        </div>

        <h1 class="relative font-display text-4xl sm:text-5xl font-bold leading-[1.15] mb-4 max-w-2xl">
            <span class="text-ink">Choose your</span><br>
            <span class="text-gold-light">game to begin</span>
        </h1>

        <p class="relative text-[15px] text-ink-muted leading-relaxed max-w-md">
            A short adaptive quiz maps where you stand. We'll surface what to work on first.
        </p>

    </section>

    {{-- Game cards --}}
    <div class="flex-1 flex flex-col items-center px-6 pb-20">
        <div class="w-full max-w-5xl">

            <div class="flex items-center gap-4 mb-8">
                <div class="flex-1 h-px bg-gold/20"></div>
                <span class="text-[11px] font-semibold text-gold uppercase tracking-[0.2em]">Select a Game</span>
                <div class="flex-1 h-px bg-gold/20"></div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">

                {{-- World of Warcraft — Emerald --}}
                @php $wowUrl = config('app.screener_wow_module_id') ? route('modules.quiz', config('app.screener_wow_module_id')) : route('modules.index'); @endphp
                <a href="{{ $wowUrl }}"
                   class="rounded-lg border bg-emerald-900/20 border-emerald-500/30
                          hover:border-emerald-500/60 hover:shadow-[0_0_28px_rgba(16,185,129,0.12)]
                          p-5 block group transition-all duration-200">
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-wow" class="w-9 h-9 shrink-0 text-emerald-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-emerald-300 transition-colors leading-tight">World of Warcraft PvP</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Arena tactics, class mechanics, dampening, and cooldown management.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-emerald-400">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Growth</span>
                                </div>
                                <span class="text-[11px] font-semibold text-emerald-400 group-hover:translate-x-0.5 transition-transform">Start →</span>
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
                @php $sc2Url = config('app.screener_sc2_module_id') ? route('modules.quiz', config('app.screener_sc2_module_id')) : route('modules.index'); @endphp
                <a href="{{ $sc2Url }}"
                   class="rounded-lg border bg-sky-900/20 border-sky-500/30
                          hover:border-sky-500/60 hover:shadow-[0_0_28px_rgba(56,189,248,0.12)]
                          p-5 block group transition-all duration-200">
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-sc2" class="w-9 h-9 shrink-0 text-sky-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-sky-300 transition-colors leading-tight">StarCraft II</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Build orders, macro cycles, scouting, and matchup-specific strategy.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-sky-400">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Strategy</span>
                                </div>
                                <span class="text-[11px] font-semibold text-sky-400 group-hover:translate-x-0.5 transition-transform">Start →</span>
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
                @php $lolUrl = config('app.screener_lol_module_id') ? route('modules.quiz', config('app.screener_lol_module_id')) : route('modules.index'); @endphp
                <a href="{{ $lolUrl }}"
                   class="rounded-lg border bg-red-900/20 border-red-500/30
                          hover:border-red-500/60 hover:shadow-[0_0_28px_rgba(248,113,113,0.12)]
                          p-5 block group transition-all duration-200">
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-lol" class="w-9 h-9 shrink-0 text-red-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-red-300 transition-colors leading-tight">League of Legends</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Wave management, vision control, champion matchups, and teamfight positioning.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-red-400">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Mastery</span>
                                </div>
                                <span class="text-[11px] font-semibold text-red-400 group-hover:translate-x-0.5 transition-transform">Start →</span>
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

                {{-- Poker — Purple --}}
                @php $pokerUrl = config('app.screener_poker_module_id') ? route('modules.quiz', config('app.screener_poker_module_id')) : route('modules.index'); @endphp
                <a href="{{ $pokerUrl }}"
                   class="rounded-lg border bg-purple-900/20 border-purple-500/30
                          hover:border-purple-500/60 hover:shadow-[0_0_28px_rgba(192,132,252,0.12)]
                          p-5 block group transition-all duration-200">
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="badge-poker" class="w-9 h-9 shrink-0 text-purple-400"/>
                        <h3 class="font-display text-[15px] font-semibold text-ink group-hover:text-purple-300 transition-colors leading-tight">Poker</h3>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0 flex flex-col">
                            <svg class="w-2.5 h-2.5 text-gold mb-2" viewBox="0 0 10 10" fill="currentColor"><path d="M5 0L10 5L5 10L0 5Z"/></svg>
                            <p class="text-[12px] text-ink-muted leading-relaxed mb-4 flex-1">Hand ranges, pot odds, position, and reading opponents at the table.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-purple-400">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path d="M6 1.5L10.5 10.5L1.5 10.5Z"/></svg>
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em]">Edge</span>
                                </div>
                                <span class="text-[11px] font-semibold text-purple-400 group-hover:translate-x-0.5 transition-transform">Start →</span>
                            </div>
                        </div>
                        <svg class="w-24 h-24 shrink-0" viewBox="0 0 130 130" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <polygon points="65,15 108,40 108,90 65,115 22,90 22,40" stroke="#c084fc" stroke-width="0.75" fill="none" opacity="0.18"/>
                            <polygon points="65,28 98,46 98,84 65,103 32,84 32,46" stroke="#c084fc" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,40 87,53 87,78 65,90 43,78 43,53" stroke="#c084fc" stroke-width="0.75" fill="none" opacity="0.12"/>
                            <polygon points="65,53 76,59 76,71 65,78 54,71 54,59" stroke="#c084fc" stroke-width="0.75" fill="none" opacity="0.08"/>
                            <line x1="65" y1="65" x2="65"  y2="15"  stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="40"  stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="108" y2="90"  stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="65"  y2="115" stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="90"  stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <line x1="65" y1="65" x2="22"  y2="40"  stroke="#c084fc" stroke-width="0.5" opacity="0.18"/>
                            <circle cx="65" cy="15"  r="1.5" fill="#c084fc" opacity="0.45"/>
                            <circle cx="108" cy="40" r="1.5" fill="#c084fc" opacity="0.45"/>
                            <circle cx="108" cy="90" r="1.5" fill="#c084fc" opacity="0.45"/>
                            <circle cx="65" cy="115" r="1.5" fill="#c084fc" opacity="0.45"/>
                            <circle cx="22" cy="90"  r="1.5" fill="#c084fc" opacity="0.45"/>
                            <circle cx="22" cy="40"  r="1.5" fill="#c084fc" opacity="0.45"/>
                            <text x="65"  y="9"   text-anchor="middle" font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">E</text>
                            <text x="115" y="38"  text-anchor="start"  font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">X</text>
                            <text x="115" y="93"  text-anchor="start"  font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">I</text>
                            <text x="65"  y="125" text-anchor="middle" font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">D</text>
                            <text x="15"  y="93"  text-anchor="end"    font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">C</text>
                            <text x="15"  y="38"  text-anchor="end"    font-size="6.5" fill="#c084fc" opacity="0.55" font-family="monospace">A</text>
                            <polygon points="65,20 100,52 96,85 65,105 40,80 45,50"
                                     fill="#c084fc" fill-opacity="0.22" stroke="#c084fc" stroke-width="1.5" stroke-opacity="0.8"/>
                            <circle cx="65" cy="65" r="2.5" fill="#c084fc" opacity="0.7"/>
                        </svg>
                    </div>
                </a>

            </div>

            {{-- Guest sign-up nudge --}}
            @guest
            <p class="text-center text-[12px] text-ink-subtle mt-8">
                Results are saved when you
                <a href="{{ route('register') }}" class="text-gold hover:text-gold-light transition-colors">create a free account</a>
                after your quiz.
            </p>
            @endguest

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
                <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer"
                   class="hover:text-gold transition-colors">Discord</a>
                <a href="{{ route('login') }}"    class="hover:text-gold transition-colors">Sign In</a>
                <a href="{{ route('register') }}" class="hover:text-gold transition-colors">Sign Up</a>
            </div>
        </div>
    </footer>

</body>
</html>
