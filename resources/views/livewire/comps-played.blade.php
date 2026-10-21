<div class="max-w-[1400px] mx-auto px-4 py-8">
    <a href="{{ route('leaderboards') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; Leaderboards</a>

    <div class="flex flex-wrap items-center gap-3 mt-3 mb-5">
        <span class="flex -space-x-1">
            @foreach ($comp['classes'] as $slug)
                @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-9 h-9"/> @endif
            @endforeach
        </span>
        @if ($match)
            <span class="text-ink-subtle text-[13px]">vs</span>
            <span class="flex -space-x-1">
                @foreach ($match['classes'] as $slug)
                    @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-9 h-9"/> @endif
                @endforeach
            </span>
        @endif
        <div>
            <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Playing against</p>
            <h1 class="font-display text-2xl text-ink leading-tight">{{ $title }}</h1>
            <p class="text-[12.5px] text-ink-muted">
                They won {{ $record[0] }} and lost {{ $record[1] }} of {{ $record[2] }} {{ \Illuminate\Support\Str::plural('game', $record[2]) }} this season.
                Read as the team facing them: "you" is that team, "they" is {{ $comp['nick'] ?? 'this comp' }}. No player is named.
            </p>
        </div>
    </div>

    @if ($state === 'open')
        {{-- The app's own comp page, drawn as its own document: its styles stay its own. --}}
        <iframe src="{{ route('comps.played.card', array_filter(['key' => $comp['key'], 'vs' => $match['key'] ?? null])) }}"
                class="w-full rounded-xl bg-surface-0" style="min-height: 80vh; border: 0"
                onload="try { this.style.height = (this.contentDocument.documentElement.scrollHeight + 20) + 'px' } catch (e) {}"
                title="{{ $title }}"></iframe>
    @else
        <div class="relative rounded-xl overflow-hidden" style="height: 70vh">
            {{-- An example of what opens, out of focus: the sample game's comp page. --}}
            <iframe src="{{ route('wow.sample.page', 'comp') }}" class="absolute inset-0 w-full h-full pointer-events-none"
                    style="border: 0; filter: blur(6px); transform: scale(1.02)" tabindex="-1" aria-hidden="true" title="Example comp card"></iframe>
            <div class="absolute inset-0 bg-black/40"></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="linear-card bg-surface-1 p-6 max-w-md text-center shadow-2xl">
                    @if ($state === 'guest')
                        <h2 class="font-display text-xl text-ink mb-2">Sign up to open the {{ $title }} card</h2>
                        <p class="text-[13.5px] text-ink-muted mb-5">
                            Their goes, the crowd control they chain, who they kill and every game this season, from real 3v3 games.
                            It's free: make an account and connect the MindCollector app, and your own games join the board too.
                        </p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <a href="{{ route('register') }}" class="btn-primary">Sign up free</a>
                            <a href="{{ route('login') }}" class="btn-secondary">Sign in</a>
                        </div>
                    @else
                        <h2 class="font-display text-xl text-ink mb-2">Connect the app to open the {{ $title }} card</h2>
                        <p class="text-[13.5px] text-ink-muted mb-5">
                            Comp cards are for players who share their games: install the MindCollector app and connect it, and this
                            card opens, with every comp on the board.
                        </p>
                        <a href="{{ route('app') }}" wire:navigate class="btn-primary">Get the app</a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
