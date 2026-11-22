{{-- MindCollector's home (PlatformController::home): what it is, and a card per game. Laid out again
     2026-11-19 (Chriso: "make this homepage look better"), when Midnight and Forever became tabs of
     their own: a hero with the two things to do, then Midnight, Forever and StarCraft II side by side,
     each in its own colour, as the sidebar's tabs are. --}}
<x-app-layout title="MindCollector: Understand Your Games"
              description="In-depth arena analysis that understands goes, defensive misplays and missed opportunities. Find out why you lost and what to improve. Plus MindCollector Auction House for WoW Forever."
              og-image="/images/app/card.webp">
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-12">

    {{-- Hero --}}
    <section class="relative overflow-hidden rounded-2xl bg-surface-1 ring-1 ring-white/5 px-6 py-9 sm:px-10 sm:py-12">
        <div class="pointer-events-none absolute inset-0"
             style="background:
                radial-gradient(ellipse at 0% 0%, {{ $games['wow']['color'] }}30, transparent 55%),
                radial-gradient(ellipse at 100% 100%, {{ ($games['forever']['color'] ?? $games['wow']['color']) }}1c, transparent 50%)"></div>

        <div class="relative grid lg:grid-cols-[1.05fr_1fr] gap-10 items-center">
            <div>
                <p class="page-eyebrow">MindCollector</p>
                <h1 class="font-display text-ink text-[40px] sm:text-[58px] leading-[1.02] tracking-tight mt-3">
                    Understand<br>Your Games
                </h1>
                <p class="text-[16px] text-ink-muted mt-5 max-w-xl leading-relaxed">
                    {{-- Chriso's own words (22 Nov), in place of the generated line --}}
                    In-depth arena analysis with context-aware tracking that understands goes, defensive misplays
                    and missed opportunities. Find out why you lost and what areas you need to improve in. Record
                    matches with a timeline display of each GCD. Uses minimal resources and learns from the games
                    you play.
                </p>
                <div class="flex flex-wrap items-center gap-3 mt-7">
                    <a href="{{ route('app') }}" wire:navigate class="btn-primary !px-7 !py-3 !text-[15px]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                        Get the app
                    </a>
                    <a href="{{ route('wow.sample') }}" class="btn-secondary !px-6 !py-3 !text-[14px]">See a game read</a>
                </div>
                <p class="text-[12.5px] text-ink-subtle mt-4">Free for Windows 10 and 11. It updates itself.</p>
            </div>

            {{-- Two of the real pages, one over the other: a game read in Midnight, the addon in Forever. --}}
            <div class="relative hidden sm:block lg:pl-4 pb-10 lg:pb-14">
                <img src="/images/app/card.webp" alt="A Midnight arena game read in MindCollector" width="1600" height="1045"
                     class="w-full h-auto rounded-xl ring-1 ring-white/10 shadow-2xl shadow-black/70">
                @if (isset($games['forever']))
                    <img src="/images/forever/professions.webp" alt="MindCollector Auction House in WoW Forever" width="924" height="548" loading="lazy"
                         class="absolute -bottom-2 -left-4 lg:-left-6 w-[58%] h-auto rounded-lg ring-1 ring-white/10 shadow-2xl shadow-black/80">
                @endif
            </div>
        </div>
    </section>

    {{-- The games, each in its colour, as the sidebar's tabs --}}
    <section>
        <div class="flex items-end justify-between gap-4 px-1 mb-4">
            <div>
                <h2 class="text-[22px] font-bold text-ink">Pick your game</h2>
                <p class="text-[13.5px] text-ink-muted mt-1">Each one is a tab in the menu, so you can move between them any time.</p>
            </div>
        </div>
        <div class="grid {{ count($games) >= 3 ? 'md:grid-cols-3' : 'md:grid-cols-2' }} gap-4">
            @foreach ($games as $slug => $g)
                @php $isLive = $g['status'] === 'live'; $open = $isLive ? [] : \App\Http\Controllers\PlatformController::openNow($slug); @endphp
                <a href="{{ route($g['route']) }}" wire:navigate
                   class="group relative flex flex-col overflow-hidden rounded-xl bg-surface-1 ring-1 ring-white/5 hover:ring-white/15 hover:-translate-y-0.5 transition">
                    {{-- The picture: the game's own page, or for a game not open yet, what is --}}
                    <div class="relative aspect-[16/10] overflow-hidden bg-frame"
                         style="background: radial-gradient(ellipse at 50% 0%, {{ $g['color'] }}33, transparent 70%), #0b0b0b">
                        @if (! empty($g['image']))
                            <img src="{{ $g['image'] }}" alt="{{ $g['name'] }} in MindCollector" loading="lazy"
                                 class="absolute inset-x-4 top-4 w-[calc(100%-2rem)] h-auto rounded-md ring-1 ring-white/10 shadow-xl shadow-black/60 group-hover:scale-[1.02] origin-top transition-transform duration-300">
                        @else
                            <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6">
                                <x-mc-icon :name="$g['icon']" class="w-14 h-14" style="color: {{ $g['color'] }}" />
                                @foreach ($open as $n)
                                    <span class="w-full max-w-[15rem] flex items-center gap-2 rounded-full bg-white/[.06] px-3 py-1.5 text-[12px] text-ink-muted">
                                        <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background: {{ $g['color'] }}"></span>
                                        {{ $n['title'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        <div class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-surface-1 to-transparent"></div>
                    </div>

                    <div class="flex-1 flex flex-col p-5 pt-3">
                        <div class="flex items-center gap-2.5">
                            <x-mc-icon :name="$g['icon']" class="w-7 h-7 shrink-0" style="color: {{ $g['color'] }}" />
                            <h3 class="text-[17px] font-bold text-ink">{{ $g['name'] }}</h3>
                        </div>
                        <div class="mt-2.5">
                            @if (! empty($g['new']))
                                <span class="badge-gold">New</span>
                            @elseif ($isLive)
                                <span class="badge-green">Live</span>
                            @elseif ($open)
                                <span class="badge-blue">Quizzes open</span>
                            @else
                                <span class="badge-gray">Coming soon</span>
                            @endif
                        </div>
                        <p class="text-[13.5px] text-ink-muted mt-2.5 flex-1 leading-relaxed">{{ $g['tagline'] }}</p>
                        <span class="mt-4 text-[13px] font-semibold inline-flex items-center gap-1" style="color: {{ $g['color'] }}">
                            {{ $isLive ? ($g['cta'] ?? 'Open '.$g['short']) : ($open ? 'Try the quizzes' : 'See what\'s coming') }}
                            <span class="group-hover:translate-x-0.5 transition-transform">&rarr;</span>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section>
        <h2 class="text-[22px] font-bold text-ink px-1 mb-4">How it works</h2>
        <ol class="grid sm:grid-cols-3 gap-4">
            @foreach ([
                ['Play as you normally do', 'The MindCollector app picks up each game when it ends. Nothing to start, nothing to export.'],
                ['Every game analysed', 'Who died and why, your goes, defensive misplays and the cooldowns you never pressed.'],
                ['See what to improve', 'Your damage, defensives and CC compared with other players of your spec, session by session.'],
            ] as $i => [$title, $body])
                <li class="relative rounded-xl bg-surface-1 ring-1 ring-white/5 p-5">
                    <span class="font-display text-[44px] leading-none text-gold/25">{{ $i + 1 }}</span>
                    <p class="text-[15px] font-semibold text-ink mt-3">{{ $title }}</p>
                    <p class="text-[13.5px] text-ink-muted mt-1.5 leading-relaxed">{{ $body }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- The policies from the front page (2026-10-29): Google's review of the YouTube upload wants the
         home page to link the privacy policy, and a player should find both without signing up. --}}
    <footer class="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-line pt-5 text-[12.5px] text-ink-subtle px-1 pb-2">
        <span class="text-ink-muted font-semibold">MindCollector</span>
        <a href="{{ route('privacy') }}" class="hover:text-ink">Privacy policy</a>
        <a href="{{ route('terms') }}" class="hover:text-ink">Terms</a>
        <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="hover:text-ink">Discord</a>
    </footer>
</div>
</x-app-layout>
