{{-- One spec's arena guide, counted from real games (SpecPvpController::show). --}}
@php
    $pct = fn ($x) => round($x * 100).'%';
    $short = $spec->name;
    $title = "{$name} PvP Guide & Talents ({$patch})";
    $topTalents = array_slice(array_keys($page['pvpTalents']), 0, 3);
    $description = "{$name} arena guide for {$patch}, counted from {$page['rounds']} rated rounds: the PvP talents {$short} players take"
        .($topTalents ? ' ('.implode(', ', $topTalents).')' : '')
        .', what they press in a go, the defensives they use when targeted, and the comps they play.';
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
            How {{ $short }} {{ $spec->gameClass->name }}s actually play arena: the PvP talents they take, what they press
            when they go, how they survive, and the comps they play. Counted from
            <span class="text-ink font-semibold">{{ number_format($page['rounds']) }} rated rounds</span> and
            <span class="text-ink font-semibold">{{ $page['players'] }} different {{ $short }} players</span>, not written from memory.
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
            <a href="{{ route('pvp-guides', ['classSlug' => $spec->gameClass->slug, 'specSlug' => $spec->slug, 'tab' => 'kit']) }}" class="text-gold hover:text-gold-light">{{ $short }} talents and kit</a>.
        </p>
    </section>

    {{-- ── Their goes ── --}}
    @if ($go && ! empty($go['spells']))
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">What {{ $short }} presses when they go</h2>
            <p class="text-[13px] text-ink-muted mt-1">
                The offensive cooldowns pressed in a go, and how often each one is in it, over {{ number_format($go['goes']) }} goes.
            </p>
            <div class="mt-4 space-y-2.5">
                @foreach (array_slice($go['spells'], 0, 8) as $s)
                    <div class="grid grid-cols-[minmax(0,11rem)_1fr_3rem] sm:grid-cols-[14rem_1fr_3rem] items-center gap-3 text-[13px]">
                        <span class="text-ink truncate" title="{{ $s['name'] }}">{{ $s['name'] }}</span>
                        <div class="h-2 rounded-full bg-surface-2 overflow-hidden">
                            <div class="h-full rounded-full" style="width: {{ $bar($s['share']) }}%; background: {{ $color }}"></div>
                        </div>
                        <span class="text-ink-muted tabular-nums text-right">{{ $pct($s['share']) }}</span>
                    </div>
                @endforeach
            </div>
            @if (! empty($go['healerRuns']))
                <h3 class="text-[13px] font-semibold text-ink mt-6">Crowd control on the enemy healer during a go</h3>
                <p class="text-[12px] text-ink-muted mt-1">Over {{ number_format($go['healerGoes'] ?? 0) }} goes where {{ $short }} controlled the healer.</p>
                <ul class="mt-2 space-y-1.5 text-[13px]">
                    @foreach (array_slice($go['healerRuns'], 0, 5) as $run)
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
        <h2 class="text-[16px] font-semibold text-ink">How {{ $short }} survives a go</h2>
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
                In {{ number_format($page['defensives']['goes']) }} goes that landed on a {{ $short }}, the defensives pressed and how often:
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

    {{-- ── Interrupts ── --}}
    @if (($page['interruptRounds'] ?? 0) > 0)
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">Interrupts</h2>
            <p class="text-[14px] text-ink-muted mt-2">
                A {{ $short }} lands at least one interrupt in
                <span class="text-ink font-semibold">{{ $pct($page['interruptRounds']) }}</span> of rounds.
                Across every spec it is {{ $pct($average['interruptRounds']) }}.
            </p>
        </section>
    @endif

    {{-- ── Comps ── --}}
    @if ($comps->isNotEmpty())
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">{{ $name }} 3v3 arena comps</h2>
            <p class="text-[13px] text-ink-muted mt-1">The teams {{ $short }} played in most, with their record in these games. A record is only as good as its count.</p>
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
        <h2 class="text-[18px] font-semibold text-ink">Play {{ $short }}? See this for your own games</h2>
        <p class="text-[14px] text-ink-muted mt-2 max-w-2xl">
            The free MindCollector app reads your combat log as you play and reviews every arena game: who died and why,
            which defensives you had left, and how your {{ $short }} compares with the numbers on this page.
        </p>
        <a href="{{ route('app') }}" class="btn-primary !px-6 !py-2.5 mt-5 inline-flex">Get the app for Windows</a>
    </section>

    {{-- ── Where it comes from ── --}}
    <section class="text-[12px] text-ink-subtle space-y-1.5 px-1">
        <h2 class="text-[13px] font-semibold text-ink-muted">Where these numbers come from</h2>
        <p>
            {{ number_format($page['rounds']) }} rated arena rounds with a {{ $short }} in them, {{ $months($page['from']) }} to {{ $months($page['to']) }},
            from combat logs uploaded to MindCollector. Every player in every game counts, not only the one who recorded it.
            @if ($page['mmr'])
                Most were played at {{ number_format($page['mmr']['p25']) }} to {{ number_format($page['mmr']['p75']) }} team MMR.
            @endif
            @php $mix = collect($page['brackets'])->filter(fn ($s) => $s >= 0.05)->map(fn ($s, $b) => $pct($s).' '.str_replace('Rated ', '', $b))->implode(', '); @endphp
            {{ $mix ? 'Brackets: '.$mix.'.' : '' }}
        </p>
        @if ($page['recorderShare'] >= 0.5)
            <p>Most of these rounds were recorded by the {{ $short }} players who uploaded them, so they lean towards how a few people play.</p>
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
