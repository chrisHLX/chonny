@php
    $classColor = $class ? (config('wow_classes.colors')[$class->slug] ?? '#8A8A9A') : '#8A8A9A';
    $pageTitle = $spec && $class ? "{$spec->name} {$class->name}" : 'Class Guide';

    $sourceBadge = [
        'talent' => 'badge-gold',
        'hero' => 'badge-amber',
        'class' => 'badge-blue',
        'spec' => 'badge-gold',
        'pvp' => 'badge-amber',
    ];

    $ratingLine = $playstyle
        ? "{$playstyle['sampleSize']} matches · rated {$playstyle['ratingRange'][0]}–{$playstyle['ratingRange'][1]}"
        : null;

    $fmtDate = function (?string $iso) {
        if (!$iso) return null;
        try {
            $d = \Carbon\Carbon::parse($iso);
            return $d->isToday() ? 'today' : $d->diffForHumans();
        } catch (\Throwable) { return null; }
    };

    // which specs have a promoted playstyle file — so the picker can mark them
    $withData = collect(glob(base_path('data/arena-logs/playstyle/*/*.json')))
        ->map(fn ($p) => basename(dirname($p)).'/'.basename($p, '.json'))->flip();
@endphp

<div class="max-w-5xl mx-auto px-4 py-8 space-y-5">

    {{-- Header --}}
    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Class Guide</p>
        <h1 class="font-display text-[28px] font-bold leading-tight mt-1" style="color: {{ $classColor }}">
            {{ $pageTitle }}
        </h1>
        <p class="text-[12.5px] text-ink-muted mt-1.5 max-w-2xl">
            How this spec actually plays, read from real archived arena matches: the talents top-rated
            players converge on, which of them earn their slot in-game, the burst window, and the buffs
            that drive it.
        </p>
        @if ($ratingLine)
            <p class="text-[11px] font-mono text-ink-subtle mt-2">{{ $ratingLine }}</p>
        @endif
    </div>

    {{-- Spec picker — plain navigation links, one guide per URL --}}
    <div class="linear-card px-4 py-3">
        <div class="flex flex-wrap gap-x-5 gap-y-3">
            @foreach ($picker as $c)
                @php $cc = config('wow_classes.colors')[$c->slug] ?? '#8A8A9A'; @endphp
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: {{ $cc }}">{{ $c->name }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($c->specializations as $sp)
                            @php
                                $isCurrent = $class && $c->id === $class->id && $sp->slug === $spec?->slug;
                                $has = $withData->has($c->slug.'/'.$sp->slug);
                            @endphp
                            <a href="{{ route('class-guide', ['classSlug' => $c->slug, 'specSlug' => $sp->slug]) }}"
                               wire:navigate
                               class="text-[11px] px-2 py-0.5 rounded-full border transition-colors
                                      {{ $isCurrent ? 'border-gold text-ink bg-gold-subtle' : 'border-line text-ink-muted hover:border-line-strong hover:text-ink' }}">
                                {{ $sp->name }}@if ($has && !$isCurrent)<span class="text-gold ml-1">•</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-[10px] text-ink-subtle mt-2 font-mono">• = match sample analysed</p>
    </div>

    @if (!$playstyle)
        <div class="linear-card px-6 py-4 border-l-2 border-line-strong">
            <p class="text-[12.5px] text-ink-muted">
                No match sample has been analysed for this spec yet — showing the burst window only.
                Run <code class="font-mono text-[11px] text-ink bg-surface-2 px-1.5 py-0.5 rounded">php artisan wow:analyze-spec-playstyle {{ $class?->slug }} {{ $spec?->slug }}</code>
                to populate the rest.
            </p>
        </div>
    @endif

    {{-- Core kit --}}
    @if ($bands['core'])
        <div class="linear-card px-6 py-5">
            <h2 class="font-display text-[15px] font-bold text-ink">The core kit</h2>
            <p class="text-[11.5px] text-ink-muted mt-0.5">Taken by nearly every sampled player, and put to work in nearly every game.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2 mt-3">
                @foreach ($bands['core'] as $r)
                    <div class="flex items-start gap-2.5 rounded-lg border border-line bg-surface-1 px-2.5 py-2">
                        @if ($r['spell'])
                            <x-spell-icon :spell="$r['spell']" size="w-8 h-8" class="rounded flex-shrink-0"/>
                        @else
                            <div class="w-8 h-8 rounded bg-surface-2 border border-line flex-shrink-0"></div>
                        @endif
                        <div class="min-w-0">
                            <p class="text-[12px] font-semibold text-ink leading-tight">{{ $r['talent'] }}</p>
                            <p class="text-[10.5px] text-ink-muted mt-0.5">
                                used in <span class="text-ink font-mono">{{ $r['used'] }}/{{ $bands['sample'] }}</span>
                                @if (($r['source'] ?? null) === 'pvp')<span class="badge-amber ml-1">PvP</span>@endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Situational / rarely earns its slot --}}
    @if ($bands['situational'])
        <div class="linear-card px-6 py-5">
            <h2 class="font-display text-[15px] font-bold text-ink">Often taken, rarely paid off</h2>
            <p class="text-[11.5px] text-ink-muted mt-0.5">
                Selected by a good share of the sample, but the analysis saw no in-match benefit in most of
                those games — either a matchup/length-specific pick, or a habit worth questioning.
            </p>
            <div class="space-y-1.5 mt-3">
                @foreach ($bands['situational'] as $r)
                    <div class="flex items-center gap-2.5 rounded-lg border border-line bg-surface-1 px-2.5 py-2">
                        @if ($r['spell'])
                            <x-spell-icon :spell="$r['spell']" size="w-7 h-7" class="rounded flex-shrink-0"/>
                        @else
                            <div class="w-7 h-7 rounded bg-surface-2 border border-line flex-shrink-0"></div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-[12px] font-semibold text-ink leading-tight">{{ $r['talent'] }}</p>
                            <p class="text-[10.5px] text-rose-400/90 mt-0.5 font-mono">{{ $r['topVerdict'] }}</p>
                        </div>
                        <p class="text-[10.5px] text-ink-subtle font-mono whitespace-nowrap">
                            flagged {{ $r['flagged'] }}/{{ $r['took'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Burst window --}}
    @if ($burst && !empty($burst['topDpsWindow']['steps']))
        @php $w = $burst['topDpsWindow']; @endphp
        <div class="linear-card px-6 py-5">
            <div class="flex items-baseline justify-between flex-wrap gap-2">
                <h2 class="font-display text-[15px] font-bold text-ink">Burst window</h2>
                <a href="{{ route('burst-window-talents', ['classSlug' => $class->slug, 'specSlug' => $spec->slug, 'length' => 12]) }}"
                   wire:navigate class="text-[11px] text-gold hover:text-gold-light">View the full talent build →</a>
            </div>
            <p class="text-[11.5px] text-ink-muted mt-0.5">
                The single highest-damage real {{ $w['durationSeconds'] ?? 12 }}s window in the sample
                @if (!empty($burst['generatedAt']) && $fmtDate($burst['generatedAt']))
                    <span class="text-ink-subtle">· updated {{ $fmtDate($burst['generatedAt']) }}</span>
                @endif
            </p>
            <ol class="flex flex-wrap items-center gap-1.5 mt-3">
                @foreach ($w['steps'] as $step)
                    <li class="text-[11px] px-2 py-1 rounded-md border
                               {{ $step['isCc'] ?? false ? 'border-violet/50 text-violet-hover bg-violet-subtle' : ($step['isRepeat'] ?? false ? 'border-line text-ink-subtle' : 'border-line-strong text-ink') }}">
                        {{ $step['displayName'] ?? $step['name'] }}
                    </li>
                    @if (!$loop->last)<li class="text-ink-subtle text-[10px]">→</li>@endif
                @endforeach
            </ol>
        </div>
    @endif

    {{-- Buffs that drive it --}}
    @if ($buffs)
        <div class="linear-card px-6 py-5">
            <h2 class="font-display text-[15px] font-bold text-ink">Buffs &amp; procs that drive it</h2>
            <p class="text-[11.5px] text-ink-muted mt-0.5">Self-buffs with the most uptime across the sample, and the selected talents that feed them.</p>
            <div class="space-y-1.5 mt-3">
                @foreach ($buffs as $b)
                    <div class="rounded-lg border border-line bg-surface-1 px-3 py-2">
                        <div class="flex items-center gap-3">
                            <span class="text-[12px] font-semibold text-ink w-40 flex-shrink-0 truncate">{{ $b['buff'] }}</span>
                            <div class="flex-1 h-1.5 rounded bg-surface-3 overflow-hidden">
                                <div class="h-full bg-gold/70 rounded" style="width: {{ min(100, $b['avgUptime']) }}%"></div>
                            </div>
                            <span class="text-[10.5px] font-mono text-ink-muted w-28 text-right flex-shrink-0">
                                {{ $b['avgUptime'] }}% avg{{ $b['maxStack'] > 1 ? ' · '.$b['maxStack'].' stk' : '' }}
                            </span>
                        </div>
                        @if ($b['feeders'])
                            <p class="text-[10px] text-ink-subtle mt-1 font-mono">← {{ implode(', ', array_slice($b['feeders'], 0, 8)) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Also commonly taken --}}
    @if ($bands['rest'])
        <div class="linear-card px-6 py-4">
            <h2 class="font-display text-[13px] font-bold text-ink-muted uppercase tracking-wide">Also commonly taken</h2>
            <div class="flex flex-wrap gap-1 mt-2">
                @foreach ($bands['rest'] as $r)
                    <span class="text-[11px] px-2 py-0.5 rounded-full border border-line text-ink-muted">
                        {{ $r['talent'] }} <span class="text-ink-subtle font-mono">{{ $r['took'] }}/{{ $bands['sample'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The sample --}}
    @if ($playstyle)
        <details class="linear-card px-6 py-4 group">
            <summary class="cursor-pointer text-[12px] font-semibold text-ink-muted list-none flex items-center gap-2">
                <span class="text-ink-subtle group-open:rotate-90 transition-transform">▶</span>
                The sample — {{ $playstyle['sampleSize'] }} matches
            </summary>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-[11.5px]">
                    <thead>
                        <tr class="text-ink-subtle text-left border-b border-line-soft">
                            <th class="py-1 pr-4 font-medium">Player</th>
                            <th class="py-1 pr-4 font-medium">Rating</th>
                            <th class="py-1 pr-4 font-medium">Length</th>
                            <th class="py-1 pr-4 font-medium">Flagged talents</th>
                        </tr>
                    </thead>
                    <tbody class="text-ink-muted">
                        @foreach ($playstyle['matches'] as $m)
                            @php
                                $flag = collect($m['talentAnalysis'])->filter(fn ($r) =>
                                    \Illuminate\Support\Str::startsWith($r['verdict'], ['UNUSED', 'DEAD', 'NO PROC']))->count();
                                $localised = ($m['localeAsciiRatio'] ?? 1) < 0.6;
                            @endphp
                            <tr class="border-b border-line-soft/50">
                                <td class="py-1.5 pr-4 text-ink">{{ $m['match']['player'] }}@if ($localised)<span class="text-ink-subtle text-[9px] ml-1" title="Locale-translated log, names resolved via spell_id">·i18n</span>@endif</td>
                                <td class="py-1.5 pr-4 font-mono">{{ $m['rating'] }}</td>
                                <td class="py-1.5 pr-4 font-mono">{{ $m['match']['durationSec'] }}s</td>
                                <td class="py-1.5 pr-4 font-mono">{{ $flag }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

</div>
