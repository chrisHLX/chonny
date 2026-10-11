@php
    $classColors = config('wow_classes.colors', []);
    $feed = $this->feed;
    $pool = $this->pool;
    $icon = $this->icons;
    $hunter = $classColors['hunter'] ?? '#AAD372';
    $warlock = $classColors['warlock'] ?? '#8788EE';
    $dk = $classColors['deathknight'] ?? ($classColors['death-knight'] ?? '#C41E3A');
    $download = $this->appAvailable ? route('coach.app') : route('app');
@endphp

{{-- The public front page (App\Livewire\Landing). Since 2026-10-12 it leads with the desktop app: a
     download, a replica of the app with an example game, the steps; the planning tools follow. --}}
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10">

    {{-- The app ------------------------------------------------------------------------------- --}}
    <section class="page-hero -mx-2 sm:mx-0">
        <p class="page-eyebrow">MindCollector for Windows &middot; free</p>
        <h1 class="page-title max-w-4xl">Every arena game you play, reviewed.</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl">
            The app runs in the background while you play and sends each game here when it ends. You get
            a card for every game: who died, what killed them, what you still had, and what to work on next.
        </p>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            <a href="{{ $download }}" class="btn-primary !px-8 !py-3.5 !text-[15px]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                Download for Windows
            </a>
            <a href="{{ route('app') }}" class="btn-ghost">How it works</a>
        </div>
        <p class="text-[12.5px] text-ink-subtle mt-3">
            Windows 10 or 11. The first time, Windows may say it "protected your PC": click More info, then Run anyway.
        </p>
    </section>

    {{-- A replica of the app, with a made-up game (a real game's page names real players). --}}
    <section class="mt-8" aria-label="What the app looks like">
        <div class="rounded-xl bg-frame p-2 shadow-2xl shadow-black/60 ring-1 ring-white/5">
            <div class="flex items-center gap-2 px-2 pb-2 text-[12px] text-ink-muted">
                <span class="w-3 h-3 rounded-full bg-gold/80"></span> MindCollector
                <span class="ml-auto text-ink-subtle">Example game</span>
            </div>
            <div class="flex gap-2 min-h-[420px]">
                {{-- Left: navigation and the library --}}
                <div class="hidden md:flex flex-col gap-2 w-64 shrink-0">
                    <div class="rounded-lg bg-surface-0 p-3">
                        <p class="text-[17px] font-bold px-1 pb-2"><span class="text-ink">Mind</span><span class="text-gold">Collector</span></p>
                        @foreach (['Games', 'Improve', 'Comps', 'Classes'] as $i => $nav)
                            <p class="relative px-3 py-2 rounded-md text-[13.5px] font-semibold {{ $i === 0 ? 'bg-surface-2 text-ink' : 'text-ink-muted' }}">
                                @if ($i === 0)<span class="absolute left-0 top-2 bottom-2 w-[3px] rounded-full bg-gold"></span>@endif{{ $nav }}
                            </p>
                        @endforeach
                    </div>
                    <div class="flex-1 rounded-lg bg-surface-0 p-2">
                        <p class="px-2 pt-1 pb-2 text-[14px] font-bold text-ink">Your games <span class="text-ink-muted font-semibold ml-1">24</span></p>
                        @foreach ([
                            ['3v3', 'Sat 14:32 · vs Death Knight, Warlock, Priest', 'Won', 1],
                            ['Solo Shuffle', 'Sat 13:58', '4-2', 1],
                            ['2v2', 'Sat 13:20 · vs Rogue, Druid', 'Lost', -1],
                            ['3v3', 'Fri 22:11 · vs Warrior, Shaman, Paladin', 'Won', 1],
                        ] as $i => [$bracket, $sub, $badge, $tone])
                            <div class="relative flex items-center gap-2 px-3 py-2 rounded-md {{ $i === 0 ? 'bg-surface-3' : '' }}">
                                @if ($i === 0)<span class="absolute left-0 top-3 bottom-3 w-[3px] rounded-full bg-gold"></span>@endif
                                <div class="min-w-0">
                                    <p class="text-[13px] font-semibold text-ink truncate">Your Hunter &middot; {{ $bracket }}</p>
                                    <p class="text-[11.5px] text-ink-muted truncate">{{ $sub }}</p>
                                </div>
                                <span class="ml-auto shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full {{ $tone > 0 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-red-500/15 text-red-300' }}">{{ $badge }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Right: the game card --}}
                <div class="flex-1 min-w-0 rounded-lg bg-surface-0 overflow-hidden">
                    <div class="px-5 pt-6 pb-4 bg-gradient-to-b from-emerald-700/40 via-emerald-900/20 to-transparent">
                        <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 align-middle">WON</span>
                        <span class="text-[24px] font-extrabold text-ink align-middle ml-2">3v3</span>
                        <span class="text-[12.5px] text-ink-muted align-middle ml-2">Sat 14:32 &middot; 2:58</span>
                        <div class="flex flex-wrap gap-2 mt-4">
                            @foreach (['Summary', 'Basics', 'Damage & healing', 'Notes'] as $i => $tab)
                                <span class="text-[12px] font-semibold px-3 py-1 rounded-full {{ $i === 0 ? 'bg-ink text-black' : 'bg-white/[.08] text-ink' }}">{{ $tab }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="px-5 pb-5">
                        <p class="text-[15px] font-bold text-ink mb-2">How it ended</p>
                        <div class="rounded-lg bg-surface-1 p-4">
                            <p class="text-[13.5px]"><span class="text-ink-muted">2:41</span> <b style="color: {{ $dk }}">Their Death Knight</b> died <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300">theirs</span></p>
                            <p class="text-[12.5px] text-ink-muted mt-2">Killing blow
                                @if (isset($icon['Kill Shot']))<img src="{{ $icon['Kill Shot'] }}" alt="" class="inline w-4 h-4 rounded mx-1">@endif<b class="text-ink">Kill Shot</b>
                                &nbsp;&middot;&nbsp; their healer <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-red-500/15 text-red-300">locked out at the death</span>
                            </p>
                            <div class="mt-3 space-y-1.5">
                                @foreach ([['Kill Command', 'You', $hunter, 34], ['Chaos Bolt', 'Your Warlock', $warlock, 27], ['Bloodshed', 'You', $hunter, 19], ['Barbed Shot', 'You', $hunter, 11]] as [$spell, $who, $color, $pct])
                                    <div class="flex items-center gap-2 text-[12.5px]">
                                        @if (isset($icon[$spell]))<img src="{{ $icon[$spell] }}" alt="" class="w-5 h-5 rounded">@else<span class="w-5 h-5 rounded bg-surface-3"></span>@endif
                                        <span class="w-40 sm:w-48 truncate"><b class="text-ink">{{ $spell }}</b> <span style="color: {{ $color }}">{{ $who }}</span></span>
                                        <span class="flex-1 h-1.5 rounded-full bg-white/10 overflow-hidden"><span class="block h-full rounded-full" style="width: {{ $pct }}%; background: {{ $color }}"></span></span>
                                        <span class="w-9 text-right text-ink-muted">{{ $pct }}%</span>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-[12px] text-ink-muted mt-3">Defensives in the last 30s:
                                @foreach ([['Icebound Fortitude', '12s'], ['Anti-Magic Shell', '6s']] as [$spell, $ago])
                                    @if (isset($icon[$spell]))<img src="{{ $icon[$spell] }}" alt="" class="inline w-4 h-4 rounded ml-2 mr-1">@endif<span class="text-ink">{{ $spell }}</span> {{ $ago }} before
                                @endforeach
                            </p>
                        </div>
                        <p class="text-[15px] font-bold text-ink mt-4 mb-2">Your answers to their goes</p>
                        <div class="rounded-lg bg-surface-1 p-4 text-[12.5px] text-ink-muted">
                            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 mr-1">GOOD</span>
                            <b class="text-ink">0 of 3</b> of their goes met two of your big defensives already down.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- What it does --------------------------------------------------------------------------- --}}
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-10">
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">How each death happened</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">The killing blow, which abilities did the damage, whether the healer was locked out, and the defensives that were used or still ready.</p>
        </div>
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">One thing to work on</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">Each character gets a focus: the habit you're furthest behind other players of your spec on, checked again after your next session.</p>
        </div>
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">Compared with real players</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">
                @if ($pool['games'] > 0)
                    Your numbers sit next to {{ number_format($pool['games']) }} games and {{ number_format($pool['players']) }} players across {{ $pool['specs'] }} specs, and every game uploaded adds to them.
                @else
                    Your numbers sit next to every player of your spec we've measured, and every game uploaded adds to them.
                @endif
            </p>
        </div>
    </section>

    {{-- Three steps ---------------------------------------------------------------------------- --}}
    <section class="mt-10 linear-card p-6 sm:p-8">
        <h2 class="text-[22px] font-bold text-ink">Set up in a minute</h2>
        <ol class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
            @foreach ([
                ['Download and open it', 'It installs itself and sits in the tray by the clock.'],
                ['Connect your account', 'It opens this site. Sign in or make an account, and click Connect.'],
                ['Play arena', 'Each game shows up about a minute after it ends. 2v2, 3v3 and Solo Shuffle.'],
            ] as $i => [$title, $text])
                <li class="flex gap-3">
                    <span class="w-8 h-8 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                    <div><p class="text-[15px] font-bold text-ink">{{ $title }}</p><p class="text-[13.5px] text-ink-muted mt-0.5">{{ $text }}</p></div>
                </li>
            @endforeach
        </ol>
        <a href="{{ $download }}" class="btn-primary mt-7">Download for Windows</a>
    </section>

    {{-- The planning tools -------------------------------------------------------------------- --}}
    <section class="mt-14">
        <h2 class="text-[22px] font-bold text-ink">Plan your games</h2>
        <p class="text-[14px] text-ink-muted mt-1">Spell, talent and cooldown data from the live game, for when you're not playing.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
            @foreach ([
                [route('wow-comps'), 'Build a 3v3 comp', 'Pick three specs and see their cooldowns, crowd control and defensives side by side.'],
                [route('matchup-lab'), 'Matchup Lab', 'Set two comps against each other and see when each side has its answers.'],
                [route('pvp-guides'), 'Class guides', 'Every spec\'s kit, burst windows and counters.'],
                [route('guides.browse'), 'Guides', 'Game plans other players have written for their comps.'],
            ] as [$href, $title, $text])
                <a href="{{ $href }}" wire:navigate class="linear-card group p-5 flex flex-col">
                    <p class="text-[16px] font-bold text-ink">{{ $title }}</p>
                    <p class="text-[13px] text-ink-muted mt-1.5 flex-1">{{ $text }}</p>
                    <span class="mt-4 w-9 h-9 rounded-full bg-gold text-black flex items-center justify-center opacity-0 translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 transition self-end">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    </span>
                </a>
            @endforeach
        </div>

        @if (count($this->presets))
            <div class="flex flex-wrap items-center gap-2 mt-4">
                <span class="text-[13px] text-ink-muted mr-1">Or start from a common comp:</span>
                @foreach ($this->presets as $preset)
                    <a href="{{ route('wow-comps', ['preset' => $preset['key']]) }}" wire:navigate wire:key="preset-{{ $preset['key'] }}" class="chip">
                        <span class="flex -space-x-1">@foreach ($preset['specs'] as $spec)<x-spec-icon :spec="$spec" size="w-5 h-5"/>@endforeach</span>
                        {{ $preset['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- The public feed ------------------------------------------------------------------------ --}}
    <section class="mt-14 min-w-0">
        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2.5">
            <h2 class="text-[20px] font-bold text-ink">Latest guides</h2>
            <a href="{{ route('guides.browse') }}" wire:navigate class="text-[13px] font-bold text-ink-muted hover:text-ink hover:underline">Show all</a>
        </div>

        <div wire:loading.class="opacity-60" wire:target="loadMore">
            @forelse ($feed['items'] as $item)
                @if ($item['type'] === 'guide')
                    <x-feed.guide-item :item="$item" :class-colors="$classColors" wire:key="feed-g-{{ $item['guide']->id }}"/>
                @else
                    <x-feed.data-update :item="$item" wire:key="feed-d-{{ $item['update']->id }}"/>
                @endif
            @empty
                <div class="py-10 text-center">
                    <p class="text-[14px] text-ink-muted">No guides yet.</p>
                    <div class="text-[13px] text-ink-subtle mt-1">
                        <x-guides.try-button class="text-gold hover:text-gold-light" label="Write the first one"/> <span>(you don't need an account)</span>
                    </div>
                </div>
            @endforelse

            @if ($feed['hasMore'] && $feedLimit < \App\Livewire\Landing::FEED_MAX)
                <div class="pt-4 text-center">
                    <button type="button" wire:click="loadMore" class="btn-ghost">Show more</button>
                </div>
            @endif
        </div>
    </section>
</div>
