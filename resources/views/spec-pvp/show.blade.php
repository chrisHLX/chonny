{{-- One spec's arena guide, counted from real games (SpecPvpController::show). --}}
@php
    $pct = fn ($x) => round($x * 100).'%';
    $short = $spec->name;
    // "a Fire Mage", "an Arcane Mage": the spec name alone is not a noun ("a Fire").
    $an = preg_match('/^[AEIOU]/i', $name) ? 'an' : 'a';
    $plural = $name.'s';
    $title = "{$name} PvP Guide & Talents ({$patch})";
    $topTalents = array_slice(array_keys($page['pvpTalents']), 0, 3);
    $description = "{$name} arena guide for {$patch}, counted from {$page['rounds']} rated rounds: the PvP talents {$name} players take"
        .($topTalents ? ' ('.implode(', ', $topTalents).')' : '')
        .', what they press in a go, the defensives they use when targeted, what to kick, and the comps they play.';
    $breadcrumbs = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'PvP guides', 'item' => route('spec-pvp.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $spec->gameClass->name, 'item' => route('spec-pvp.index').'#'.$spec->gameClass->slug],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => route('spec-pvp.show', [$spec->gameClass->slug, $spec->slug])],
        ],
    ];
    $bar = fn ($share) => max(2, round($share * 100));
    $months = fn ($date) => $date ? \Carbon\Carbon::parse($date)->format('j M Y') : '?';
    // Like every other list on the page, a row under one in twenty is left out; and like the comp
    // page, a spec with under 20 measured goes shows none (BuildGoCooldowns' "not enough measured").
    $go = $go && ($go['goes'] ?? 0) >= 20 ? $go : null;
    $goSpells = $go ? array_slice(array_values(array_filter($go['spells'] ?? [], fn ($s) => $s['share'] >= 0.05)), 0, 8) : [];
    $healerRuns = $go ? array_slice(array_values(array_filter($go['healerRuns'] ?? [], fn ($r) => $r['share'] >= 0.05)), 0, 5) : [];
    $tierLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $tierClass = ['high' => 'badge-gold', 'medium' => 'badge-amber', 'low' => 'badge-gray'];
    $asKicker = $page['priorityKicks'] ?? null;
@endphp
<x-app-layout :title="$title.' | MindCollector'" :description="$description" og-image="/images/app/card.webp" :schema="$breadcrumbs">
<div class="max-w-4xl mx-auto px-4 py-6 sm:py-10 space-y-5">

    <nav class="text-[12px] text-ink-subtle">
        <a href="{{ route('spec-pvp.index') }}" class="hover:text-ink">PvP guides</a>
        <span class="mx-1">/</span>
        <a href="{{ route('spec-pvp.index') }}#{{ $spec->gameClass->slug }}" class="hover:text-ink">{{ $spec->gameClass->name }}</a>
        <span class="mx-1">/</span>
        <span class="text-ink-muted">{{ $short }}</span>
    </nav>

    <section class="page-hero">
        <p class="page-eyebrow" style="color: {{ $color }}">{{ $patch }} arena</p>
        <h1 class="page-title">{{ $name }} PvP guide</h1>
        <p class="text-[15px] text-ink-muted mt-3 max-w-2xl">
            How {{ $plural }} actually play arena: the PvP talents they take, what they press
            when they go, how they survive, what to kick on them, and the comps they play. Counted from
            <span class="text-ink font-semibold">{{ number_format($page['rounds']) }} rated rounds</span> and
            <span class="text-ink font-semibold">{{ $page['players'] }} different {{ $name }} players</span>, not written from memory.
        </p>
    </section>

    {{-- ── PvP talents ── --}}
    <section class="linear-card p-5">
        <h2 class="text-[16px] font-semibold text-ink">{{ $name }} PvP talents</h2>
        <p class="text-[13px] text-ink-muted mt-1">The share of rounds each PvP talent was taken in.</p>
        <div class="mt-4 space-y-2.5">
            @foreach ($page['pvpTalents'] as $talent => $share)
                <div class="grid grid-cols-[minmax(0,11rem)_1fr_3rem] sm:grid-cols-[14rem_1fr_3rem] items-center gap-3 text-[13px]">
                    <span class="text-ink truncate" title="{{ $talent }}">{{ $talent }}</span>
                    <div class="h-2 rounded-full bg-surface-2 overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $bar($share) }}%; background: {{ $color }}"></div>
                    </div>
                    <span class="text-ink-muted tabular-nums text-right">{{ $pct($share) }}</span>
                </div>
            @endforeach
        </div>
        <p class="text-[12px] text-ink-subtle mt-4">
            Class and spec tree talents, with the build most players run and every cooldown adjusted for it:
            <a href="{{ route('pvp-guides', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug, 'tab' => 'kit']) }}" class="text-gold hover:text-gold-light">{{ $name }} talents and kit</a>.
        </p>
    </section>

    {{-- ── Their goes ── --}}
    @if ($goSpells)
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">What {{ $plural }} press when they go</h2>
            <p class="text-[13px] text-ink-muted mt-1">
                The offensive cooldowns pressed in a go, and how often each one is in it, over {{ number_format($go['goes']) }} goes.
            </p>
            <div class="mt-4 space-y-2.5">
                @foreach ($goSpells as $s)
                    <div class="grid grid-cols-[minmax(0,11rem)_1fr_3rem] sm:grid-cols-[14rem_1fr_3rem] items-center gap-3 text-[13px]">
                        <span class="text-ink truncate" title="{{ $s['name'] }}">{{ $s['name'] }}</span>
                        <div class="h-2 rounded-full bg-surface-2 overflow-hidden">
                            <div class="h-full rounded-full" style="width: {{ $bar($s['share']) }}%; background: {{ $color }}"></div>
                        </div>
                        <span class="text-ink-muted tabular-nums text-right">{{ $pct($s['share']) }}</span>
                    </div>
                @endforeach
            </div>
            @if ($healerRuns)
                <h3 class="text-[13px] font-semibold text-ink mt-6">Crowd control on the enemy healer during a go</h3>
                <p class="text-[12px] text-ink-muted mt-1">Over {{ number_format($go['healerGoes'] ?? 0) }} goes where {{ $an }} {{ $name }} controlled the healer.</p>
                <ul class="mt-2 space-y-1.5 text-[13px]">
                    @foreach ($healerRuns as $run)
                        <li class="flex items-baseline justify-between gap-3">
                            <span class="text-ink">{{ implode(' > ', $run['chain']) }}</span>
                            <span class="text-ink-muted tabular-nums">{{ $pct($run['share']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- ── Surviving ── --}}
    <section class="linear-card p-5">
        <h2 class="text-[16px] font-semibold text-ink">How {{ $plural }} survive a go</h2>
        <div class="grid grid-cols-2 gap-3 mt-4">
            <div class="rounded-xl bg-surface-2 p-4">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider">Dies in</p>
                <p class="text-[24px] font-semibold text-ink leading-none tabular-nums mt-2">{{ $pct($page['died']) }}</p>
                <p class="text-[12px] text-ink-muted mt-1.5">of rounds. Every spec: {{ $pct($average['died']) }}</p>
            </div>
            <div class="rounded-xl bg-surface-2 p-4">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider">Dies first in</p>
                <p class="text-[24px] font-semibold text-ink leading-none tabular-nums mt-2">{{ $pct($page['diedFirst']) }}</p>
                <p class="text-[12px] text-ink-muted mt-1.5">of rounds. Every spec: {{ $pct($average['diedFirst']) }}</p>
            </div>
        </div>

        @if ($page['defensives']['goes'] >= 10)
            <p class="text-[13px] text-ink-muted mt-5">
                In {{ number_format($page['defensives']['goes']) }} goes that landed on {{ $an }} {{ $name }}, the defensives pressed and how often:
            </p>
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-1 mt-3">
                <div>
                    <h3 class="text-[12px] font-semibold text-ink mb-1.5">Their own</h3>
                    <ul class="space-y-1.5 text-[13px]">
                        @forelse ($page['defensives']['self'] as $spell => $share)
                            <li class="flex items-baseline justify-between gap-3"><span class="text-ink">{{ $spell }}</span><span class="text-ink-muted tabular-nums">{{ $pct($share) }}</span></li>
                        @empty
                            <li class="text-ink-subtle">None pressed in more than one go in twenty.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="mt-4 sm:mt-0">
                    <h3 class="text-[12px] font-semibold text-ink mb-1.5">From their team</h3>
                    <ul class="space-y-1.5 text-[13px]">
                        @forelse ($page['defensives']['team'] as $spell => $share)
                            <li class="flex items-baseline justify-between gap-3"><span class="text-ink">{{ $spell }}</span><span class="text-ink-muted tabular-nums">{{ $pct($share) }}</span></li>
                        @empty
                            <li class="text-ink-subtle">None pressed in more than one go in twenty.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </section>

    {{-- ── What to kick on them (KickPriorities) ── --}}
    @if ($kicks || $alsoKicked)
        <section class="linear-card p-5" id="what-to-kick">
            <h2 class="text-[16px] font-semibold text-ink">What to kick on {{ $an }} {{ $name }}</h2>
            <p class="text-[13px] text-ink-muted mt-1">
                The casts worth an interrupt, ranked the way top players rank them, and how often each one was stopped
                when {{ $an }} {{ $name }} cast it in {{ number_format($kickGames) }} games this season.
            </p>
            <ul class="mt-4 divide-y divide-line">
                @foreach ($kicks as $k)
                    <li class="py-2.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="flex items-baseline gap-2.5 text-[13px]">
                            <span class="{{ $tierClass[$k['tier']] }} w-16 text-center">{{ $tierLabel[$k['tier']] }}</span>
                            <span class="text-ink">{{ $k['name'] }}</span>
                        </span>
                        @if ($k['stopped'] > 0)
                            <span class="text-[12px] text-ink-muted tabular-nums">stopped {{ number_format($k['stopped']) }} of {{ number_format($k['casts']) }} casts</span>
                        @elseif ($k['casts'] >= \App\Support\KickPriorities::ENOUGH_CASTS)
                            <span class="text-[12px] text-ink-subtle basis-full sm:basis-auto sm:text-right">
                                Never stopped in {{ number_format($k['casts']) }} casts: most likely instant or not interruptible, so save the kick
                            </span>
                        @else
                            <span class="text-[12px] text-ink-subtle tabular-nums">{{ $k['casts'] }} casts, too few to tell</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($alsoKicked)
                <p class="text-[13px] text-ink-muted mt-4">
                    Not on the list, but kicked often in these games:
                    @foreach ($alsoKicked as $a)
                        <span class="text-ink">{{ $a['name'] }}</span> ({{ number_format($a['stopped']) }} of {{ number_format($a['casts']) }} casts)@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
            @if ($kickSource)
                <p class="text-[12px] text-ink-subtle mt-4">
                    Priorities from <a href="{{ $kickSource['url'] }}" class="text-gold hover:text-gold-light" target="_blank" rel="noopener">{{ $kickSource['source'] }}'s {{ $name }} guide</a>
                    for {{ $kickSource['patch'] }}, read {{ $months($kickSource['read']) }}. The counts are from games uploaded to MindCollector.
                </p>
            @endif
        </section>
    @endif

    {{-- ── As a kicker (measure 15) ── --}}
    @if ($asKicker && $asKicker['chances'] >= 20)
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">{{ $plural }} as kickers</h2>
            <p class="text-[14px] text-ink-muted mt-2">
                {{ $plural }} met {{ number_format($asKicker['chances']) }} high-priority enemy casts with their interrupt ready, and stopped
                <span class="text-ink font-semibold">{{ number_format($asKicker['mine']) }} ({{ $pct($asKicker['mine'] / $asKicker['chances']) }})</span>.
                @if ($average['priorityKickShare'] !== null)
                    Across every spec with an interrupt it is {{ $pct($average['priorityKickShare']) }}.
                @endif
            </p>
            <p class="text-[12px] text-ink-subtle mt-3">
                A chance is a cast from the list above, begun while the player was alive, free to act, had their kick off cooldown and stood
                within its range (measured from where both players stood). The combat log has no line of sight, so a pillar may have been in the way.
            </p>
        </section>
    @endif

    {{-- ── Comps ── --}}
    @if ($comps->isNotEmpty())
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">{{ $name }} 3v3 arena comps</h2>
            <p class="text-[13px] text-ink-muted mt-1">The teams {{ $plural }} played in most, with their record in these games. A record is only as good as its count.</p>
            <ul class="mt-4 divide-y divide-line">
                @foreach ($comps as $c)
                    <li class="py-2.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="text-[13px]">
                            @foreach ($c['members'] as $m)
                                <a href="{{ route('spec-pvp.show', [$m->gameClass->slug, $m->slug]) }}" class="hover:underline" style="color: {{ $classColors[$m->gameClass->slug] ?? '#B3B3B3' }}">{{ $m->name }} {{ $m->gameClass->name }}</a>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                            @endforeach
                        </span>
                        <span class="text-[12px] text-ink-muted tabular-nums">{{ $c['games'] }} games, {{ $c['won'] }}&ndash;{{ $c['games'] - $c['won'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ── The app ── --}}
    <section class="linear-card p-6 sm:p-7" style="background: linear-gradient(135deg, {{ $color }}22, transparent 60%)">
        <h2 class="text-[18px] font-semibold text-ink">Play {{ $name }}? See this for your own games</h2>
        <p class="text-[14px] text-ink-muted mt-2 max-w-2xl">
            The free MindCollector app reads your combat log as you play and reviews every arena game: who died and why,
            which defensives you had left, which casts went through while your kick was ready, and how your {{ $name }}
            compares with the numbers on this page.
        </p>
        <a href="{{ route('app') }}" class="btn-primary !px-6 !py-2.5 mt-5 inline-flex">Get the app for Windows</a>
    </section>

    {{-- ── Where it comes from ── --}}
    <section class="text-[12px] text-ink-subtle space-y-1.5 px-1">
        <h2 class="text-[13px] font-semibold text-ink-muted">Where these numbers come from</h2>
        <p>
            {{ number_format($page['rounds']) }} rated arena rounds with {{ $an }} {{ $name }} in them, {{ $months($page['from']) }} to {{ $months($page['to']) }},
            from combat logs uploaded to MindCollector. Every player in every game counts, not only the one who recorded it.
            @if ($page['mmr'])
                Most were played at {{ number_format($page['mmr']['p25']) }} to {{ number_format($page['mmr']['p75']) }} team MMR.
            @endif
            @php $mix = collect($page['brackets'])->filter(fn ($s) => $s >= 0.05)->map(fn ($s, $b) => $pct($s).' '.str_replace('Rated ', '', $b))->implode(', '); @endphp
            {{ $mix ? 'Brackets: '.$mix.'.' : '' }}
        </p>
        @if ($page['recorderShare'] >= 0.5)
            <p>Most of these rounds were recorded by the {{ $name }} players who uploaded them, so they lean towards how a few people play.</p>
        @endif
        @if ($page['window'] === 'all')
            <p>Too few games this season, so earlier seasons are counted too.</p>
        @endif
        <p>Updated {{ $months($builtAt) }}. The numbers grow as more games are uploaded.</p>
        @if ($siblings->isNotEmpty())
            <p class="pt-2">
                Other {{ $spec->gameClass->name }} guides:
                @foreach ($siblings as $s)
                    <a href="{{ route('spec-pvp.show', [$s->gameClass->slug, $s->slug]) }}" class="text-gold hover:text-gold-light">{{ $s->name }}</a>@if (! $loop->last), @endif
                @endforeach
                &middot; <a href="{{ route('spec-pvp.index') }}" class="text-gold hover:text-gold-light">Every spec</a>
            </p>
        @endif
    </section>
</div>
</x-app-layout>
