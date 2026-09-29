@props(['go', 'icons' => []])

@php
    // One go from a played game, drawn in the guide's own sequence style (see
    // components/guides/section-steps): numbered links with the ability icon, who pressed it in
    // their class colour, and crowd control marked by who it landed on. Then what it forced, what
    // hit hardest, and how it ended. Data: MatchAnalysisService::game().
    $classColors = config('wow_classes.colors', []);
    $ours = $go['side'] === 'us';
    $roleLabel = ['healer' => 'on their healer', 'target' => 'on the target', 'cross' => 'cross CC'];
    $outcome = match ($go['outcome']) {
        'killed' => 'Killed '.($ours ? 'their ' : 'your ').($go['killed']['spec'] ?? ''),
        'set up a kill' => 'Set up the kill on '.($ours ? 'their ' : 'your ').($go['killed']['spec'] ?? ''),
        default => 'No kill',
    };
    $color = fn ($p) => $classColors[$p['class'] ?? ''] ?? '#8A8A9A';
    $k = fn ($n) => $n >= 1000000 ? round($n / 1000000, 1).'M' : round($n / 1000).'k';
    $step = 0;
@endphp

<div class="linear-card p-5 border-l-2 {{ $ours ? 'border-l-gold' : 'border-l-violet' }}">
    <div class="flex flex-wrap items-center gap-2 mb-1.5">
        <span class="{{ $ours ? 'badge-gold' : 'badge-blue' }}">{{ $ours ? 'Your go' : 'Their go' }}</span>
        <span class="text-[12px] text-ink-subtle tabular-nums">{{ $go['at'] }}</span>
        <span class="text-[11px] {{ $go['good'] ? 'text-ink-muted' : 'text-amber-400' }}">{{ $go['good'] ? 'tight' : 'loose timing' }}</span>
        @if ($go['drained'] > 0)
            <span class="text-[11px] text-ink-subtle">· {{ $go['drained'] }} of {{ $ours ? 'their' : 'your' }} defensives already down</span>
        @endif
    </div>
    <h3 class="text-[16px] font-semibold {{ $go['outcome'] === 'no kill' ? 'text-ink-muted' : 'text-ink' }}">{{ $outcome }}</h3>

    <ol class="flex flex-col gap-1.5 mt-3">
        @foreach ($go['links'] as $l)
            @php $step++; @endphp
            @if ($l['gap'] > 2)
                <li class="text-[11px] text-amber-400/80 pl-7">{{ $l['gap'] }}s gap</li>
            @endif
            <li class="flex items-center gap-3 p-2 rounded border border-line bg-surface-2">
                <span class="font-display text-[13px] text-gold tabular-nums w-4 shrink-0">{{ $step }}</span>
                <x-spell-icon :spell="$icons[$l['spell']] ?? (object) ['icon_name' => null, 'display_name' => $l['spell']]" size="w-7 h-7"/>
                <div class="flex-1 min-w-0">
                    <p class="text-[13px] text-ink leading-tight">{{ $l['spell'] }}</p>
                    @if ($l['byWho'])
                        <p class="text-[11px] leading-tight" style="color: {{ $color($l['byWho']) }}">{{ $l['byWho']['spec'] }}</p>
                    @endif
                </div>
                @if ($l['cat'] === 'control')
                    <span class="{{ $l['role'] === 'healer' ? 'badge-gold' : 'badge-gray' }} shrink-0">{{ $roleLabel[$l['role']] ?? 'CC' }}{{ ($l['alsoHit'] ?? 0) > 0 ? ' +'.$l['alsoHit'] : '' }}</span>
                @endif
                <span class="text-[11px] text-ink-subtle tabular-nums w-10 text-right shrink-0">+{{ $l['t'] }}s</span>
            </li>
        @endforeach
    </ol>

    @if ($go['forced'] !== [] || $go['burst'] !== [])
        <div class="grid sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-line">
            <div>
                <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-2">Forced from {{ $ours ? 'them' : 'you' }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($go['forced'] as $f)
                        <span class="inline-flex items-center gap-1.5 pr-2 rounded border border-line bg-surface-2" title="{{ $f['spell'] }} ({{ $f['whoPlayer']['spec'] ?? '' }}) at +{{ $f['t'] }}s">
                            <x-spell-icon :spell="$icons[$f['spell']] ?? (object) ['icon_name' => null, 'display_name' => $f['spell']]" size="w-6 h-6"/>
                            <span class="text-[11.5px] text-ink">{{ $f['spell'] }}</span>
                        </span>
                    @empty
                        <span class="text-[12px] text-ink-subtle">Nothing</span>
                    @endforelse
                </div>
            </div>
            <div>
                <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-2">
                    Hardest 6 seconds: {{ $k($go['burstTotal']) }}
                    @if ($go['burstOnHealerCc'])
                        <span class="text-gold normal-case tracking-normal">· on their healer's CC</span>
                    @endif
                </p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($go['burst'] as $b)
                        <span class="inline-flex items-center gap-1.5 pr-2 rounded border border-line bg-surface-2" title="{{ $b['spell'] }}: {{ number_format($b['amount']) }}">
                            <x-spell-icon :spell="$icons[$b['spell']] ?? (object) ['icon_name' => null, 'display_name' => $b['spell']]" size="w-6 h-6"/>
                            <span class="text-[11.5px] tabular-nums" style="color: {{ $color($b['whoPlayer'] ?? []) }}">{{ $k($b['amount']) }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
