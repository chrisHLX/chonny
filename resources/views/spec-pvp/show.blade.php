{{-- One spec's arena guide, counted from real games (SpecPvpController::show).

     Since 2026-10-17 (Chriso) the page presents what the games say as it is: shares and comparisons,
     no round, game or cast counts and no sample notes. The thresholds that keep a thin number off
     the page stay (a list row under one in twenty, a go section under 20 goes, defensives
     under 20 goes, a kick row under KickPriorities::ENOUGH_CASTS gets no figure); only the counts
     behind what is shown are no longer printed. --}}
@php
    $pct = fn ($x) => round($x * 100).'%';
    $short = $spec->name;
    // "a Fire Mage", "an Arcane Mage": the spec name alone is not a noun ("a Fire").
    $an = preg_match('/^[AEIOU]/i', $name) ? 'an' : 'a';
    $plural = $name.'s';
    $title = "{$name} PvP Guide & Talents ({$patch})";
    $topTalents = array_slice(array_keys($page['pvpTalents']), 0, 3);
    $description = "{$name} arena guide for {$patch}, counted from real rated games: the PvP talents {$name} players take"
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
    $readable = fn ($n) => \App\Support\SpecPages::readable($n);
    // Like every other list on the page, a row under one in twenty is left out; and like the comp
    // page, a spec with under 20 measured goes shows none (BuildGoCooldowns' "not enough measured").
    $go = $go && ($go['goes'] ?? 0) >= 20 ? $go : null;
    $goSpells = $go ? array_slice(array_values(array_filter($go['spells'] ?? [], fn ($s) => $s['share'] >= 0.05 && $readable($s['name']))), 0, 8) : [];
    $healerRuns = $go ? array_slice(array_values(array_filter($go['healerRuns'] ?? [], fn ($r) => $r['share'] >= 0.05)), 0, 5) : [];
    $tierLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $tierClass = ['high' => 'badge-gold', 'medium' => 'badge-amber', 'low' => 'badge-gray'];
    $asKicker = $page['priorityKicks'] ?? null;
    $selfDefs = $page['defensives']['goes'] >= 20 ? $page['defensives']['self'] : [];
    $teamDefs = $page['defensives']['goes'] >= 20 ? $page['defensives']['team'] : [];

    // At a glance: the one thing in each section a reader looks for first.
    $glance = array_values(array_filter([
        ($t = array_key_first($page['pvpTalents'])) ? ['label' => 'Most taken PvP talent', 'name' => $t, 'share' => $page['pvpTalents'][$t]] : null,
        $goSpells ? ['label' => 'Main cooldown', 'name' => $goSpells[0]['name'], 'share' => $goSpells[0]['share']] : null,
        ($d = array_key_first($selfDefs)) ? ['label' => 'First defensive', 'name' => $d, 'share' => $selfDefs[$d]] : null,
        $healerRuns ? ['label' => 'On the enemy healer', 'name' => $healerRuns[0]['chain'][0], 'share' => $healerRuns[0]['share']] : null,
    ]));
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

    <section class="page-hero relative overflow-hidden" style="background: radial-gradient(circle at 90% 0%, {{ $color }}2e, transparent 55%)">
        <div class="flex items-start gap-4 sm:gap-5">
            <x-spec-icon :spec="$spec" :color="$color" size="w-14 h-14 sm:w-20 sm:h-20" class="!rounded-xl shadow-lg" />
            <div class="min-w-0">
                <p class="page-eyebrow" style="color: {{ $color }}">{{ $patch }} arena</p>
                <h1 class="page-title">{{ $name }} PvP guide</h1>
            </div>
        </div>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            How {{ $plural }} actually play arena: the PvP talents they take, what they press when they go, how they
            survive, what to kick on them, and the comps they play. Counted from real rated games, not written from memory.
        </p>
    </section>

    {{-- ── At a glance ── --}}
    @if ($glance)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ($glance as $g)
                <div class="linear-card p-4">
                    <p class="text-[10.5px] text-ink-subtle uppercase tracking-wider">{{ $g['label'] }}</p>
                    <div class="flex items-center gap-2.5 mt-2.5">
                        <x-ability-icon :spell="$icons[$g['name']] ?? null" :name="$g['name']" :color="$color" size="w-9 h-9" />
                        <div class="min-w-0">
                            <p class="text-[13px] font-semibold text-ink leading-tight line-clamp-2" title="{{ $g['name'] }}">{{ $g['name'] }}</p>
                            <p class="text-[12px] tabular-nums" style="color: {{ $color }}">{{ $pct($g['share']) }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── PvP talents ── --}}
    <section class="linear-card p-5">
        <h2 class="text-[16px] font-semibold text-ink">{{ $name }} PvP talents</h2>
        <p class="text-[13px] text-ink-muted mt-1">How often each PvP talent is taken.</p>
        <div class="mt-4 space-y-2.5">
            @foreach ($page['pvpTalents'] as $talent => $share)
                <div class="grid grid-cols-[1.75rem_minmax(0,10rem)_1fr_3rem] sm:grid-cols-[1.75rem_13rem_1fr_3rem] items-center gap-3 text-[13px]">
                    <x-ability-icon :spell="$icons[$talent] ?? null" :name="$talent" :color="$color" />
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
            <p class="text-[13px] text-ink-muted mt-1">The offensive cooldowns in their goes, and how often each one is in it.</p>
            <div class="mt-4 space-y-2.5">
                @foreach ($goSpells as $s)
                    <div class="grid grid-cols-[1.75rem_minmax(0,10rem)_1fr_3rem] sm:grid-cols-[1.75rem_13rem_1fr_3rem] items-center gap-3 text-[13px]">
                        <x-ability-icon :spell="$icons[$s['name']] ?? null" :name="$s['name']" :color="$color" />
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
                <ul class="mt-3 space-y-2">
                    @foreach ($healerRuns as $run)
                        <li class="flex items-center justify-between gap-3 text-[13px]">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0">
                                @foreach ($run['chain'] as $step)
                                    @if (! $loop->first)<span class="text-ink-subtle">&rsaquo;</span>@endif
                                    <span class="flex items-center gap-1.5">
                                        <x-ability-icon :spell="$icons[$step] ?? null" :name="$step" :color="$color" size="w-6 h-6" />
                                        <span class="text-ink">{{ $step }}</span>
                                    </span>
                                @endforeach
                            </span>
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

        @if ($selfDefs || $teamDefs)
            <p class="text-[13px] text-ink-muted mt-5">When a go lands on {{ $an }} {{ $name }}, the defensives pressed and how often:</p>
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-1 mt-3">
                @foreach (['Their own' => $selfDefs, 'From their team' => $teamDefs] as $heading => $list)
                    @if ($list)
                        <div class="{{ $loop->first ? '' : 'mt-4 sm:mt-0' }}">
                            <h3 class="text-[12px] font-semibold text-ink mb-2">{{ $heading }}</h3>
                            <ul class="space-y-2 text-[13px]">
                                @foreach ($list as $spell => $share)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="flex items-center gap-2 min-w-0">
                                            <x-ability-icon :spell="$icons[$spell] ?? null" :name="$spell" :color="$color" size="w-6 h-6" />
                                            <span class="text-ink truncate">{{ $spell }}</span>
                                        </span>
                                        <span class="text-ink-muted tabular-nums">{{ $pct($share) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- ── What to kick on them (KickPriorities) ── --}}
    @if ($kicks || $alsoKicked)
        <section class="linear-card p-5" id="what-to-kick">
            <h2 class="text-[16px] font-semibold text-ink">What to kick on {{ $an }} {{ $name }}</h2>
            <p class="text-[13px] text-ink-muted mt-1">
                The casts worth an interrupt, ranked the way top players rank them, and how often each one is stopped this season.
            </p>
            <ul class="mt-4 divide-y divide-line">
                @foreach ($kicks as $k)
                    <li class="py-2.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <span class="flex items-center gap-2.5 text-[13px] min-w-0">
                            <span class="{{ $tierClass[$k['tier']] }} w-16 text-center shrink-0">{{ $tierLabel[$k['tier']] }}</span>
                            <x-ability-icon :spell="$icons[$k['name']] ?? null" :name="$k['name']" :color="$color" size="w-6 h-6" />
                            <span class="text-ink">{{ $k['name'] }}</span>
                        </span>
                        @if ($k['stopped'] > 0 && $k['casts'] > 0)
                            <span class="text-[12px] text-ink-muted tabular-nums">stopped {{ $pct($k['stopped'] / $k['casts']) }} of the time</span>
                        @elseif ($k['casts'] >= \App\Support\KickPriorities::ENOUGH_CASTS)
                            <span class="text-[12px] text-ink-subtle basis-full sm:basis-auto sm:text-right">
                                Never stopped: most likely instant or not interruptible, so save the kick
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($alsoKicked)
                <p class="text-[13px] text-ink-muted mt-4">
                    Not on the list, but kicked often:
                    @foreach ($alsoKicked as $a)
                        <span class="text-ink">{{ $a['name'] }}</span>@if ($a['casts'] > 0) ({{ $pct($a['stopped'] / $a['casts']) }})@endif{{ $loop->last ? '.' : ',' }}
                    @endforeach
                </p>
            @endif
            @if ($kickSource)
                <p class="text-[12px] text-ink-subtle mt-4">
                    Priorities from <a href="{{ $kickSource['url'] }}" class="text-gold hover:text-gold-light" target="_blank" rel="noopener">{{ $kickSource['source'] }}'s {{ $name }} guide</a>
                    for {{ $kickSource['patch'] }}.
                </p>
            @endif
        </section>
    @endif

    {{-- ── As a kicker (measure 15) ── --}}
    @if ($asKicker && $asKicker['chances'] >= 20)
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">{{ $plural }} as kickers</h2>
            <p class="text-[14px] text-ink-muted mt-2">
                {{ $plural }} stop <span class="text-ink font-semibold">{{ $pct($asKicker['mine'] / $asKicker['chances']) }}</span>
                of the high-priority enemy casts they meet with their interrupt ready.
                @if ($average['priorityKickShare'] !== null)
                    Across every spec with an interrupt it is {{ $pct($average['priorityKickShare']) }}.
                @endif
            </p>
            <p class="text-[12px] text-ink-subtle mt-3">
                A chance is a cast from a kick list, begun while the player was alive, free to act, had their kick off cooldown and stood
                within its range. The combat log has no line of sight, so a pillar may have been in the way.
            </p>
        </section>
    @endif

    {{-- ── Comps ── --}}
    @if ($comps->isNotEmpty())
        <section class="linear-card p-5">
            <h2 class="text-[16px] font-semibold text-ink">{{ $name }} 3v3 arena comps</h2>
            <p class="text-[13px] text-ink-muted mt-1">The teams {{ $plural }} play in most.</p>
            <div class="grid sm:grid-cols-2 gap-3 mt-4">
                @foreach ($comps as $c)
                    <div class="rounded-xl bg-surface-2 p-3 flex items-center gap-3">
                        <span class="flex -space-x-2 shrink-0">
                            @foreach ($c['members'] as $m)
                                <x-spec-icon :spec="$m" :color="$classColors[$m->gameClass->slug] ?? '#B3B3B3'" size="w-9 h-9" class="ring-2 ring-surface-2" />
                            @endforeach
                        </span>
                        <span class="text-[12.5px] leading-snug min-w-0">
                            @foreach ($c['members'] as $m)
                                <a href="{{ route('spec-pvp.show', [$m->gameClass->slug, $m->slug]) }}" class="hover:underline"
                                   style="color: {{ $classColors[$m->gameClass->slug] ?? '#B3B3B3' }}">{{ $m->name }} {{ $m->gameClass->name }}</a>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── The app ── --}}
    <section class="linear-card p-6 sm:p-7 overflow-hidden" style="background: linear-gradient(135deg, {{ $color }}22, transparent 60%)">
        <div class="grid md:grid-cols-[1fr_17rem] gap-6 items-center">
            <div>
                <h2 class="text-[18px] font-semibold text-ink">Play {{ $name }}? See this for your own games</h2>
                <p class="text-[14px] text-ink-muted mt-2">
                    The free MindCollector app reads your combat log as you play and reviews every arena game: who died and why,
                    which defensives you had left, which casts went through while your kick was ready, and how your {{ $name }}
                    compares with the numbers on this page.
                </p>
                <a href="{{ route('app') }}" class="btn-primary !px-6 !py-2.5 mt-5 inline-flex">Get the app for Windows</a>
            </div>
            <img src="/images/app/card.webp" alt="A game reviewed in the MindCollector app" loading="lazy"
                 class="w-full rounded-xl border border-line shadow-2xl">
        </div>
    </section>

    {{-- ── Where it comes from, and the other guides ── --}}
    <section class="text-[12px] text-ink-subtle space-y-2 px-1">
        <p>
            Counted from rated arena games uploaded to MindCollector: every player in every game, not only the one who recorded it.
            Updated {{ $months($builtAt) }}.
        </p>
        @if ($siblings->isNotEmpty())
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1">
                <span>Other {{ $spec->gameClass->name }} guides:</span>
                @foreach ($siblings as $s)
                    <a href="{{ route('spec-pvp.show', [$s->gameClass->slug, $s->slug]) }}" class="flex items-center gap-1.5 text-gold hover:text-gold-light">
                        <x-spec-icon :spec="$s" :color="$color" size="w-5 h-5" />{{ $s->name }}
                    </a>
                @endforeach
                <a href="{{ route('spec-pvp.index') }}" class="text-gold hover:text-gold-light">Every spec</a>
            </div>
        @endif
    </section>
</div>
</x-app-layout>
