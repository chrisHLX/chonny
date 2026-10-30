{{-- The Leaderboards filters (2026-10-30, Chriso: "the grey filtering boxes on top look too basic and we
     don't have the ability to filter properly"): one panel for the Comps and Games tabs. $mode is
     'comps' or 'games'; the chosen filters show under it, each with its own way off. --}}
@php
    $isComps = $mode === 'comps';
    $bandNow = $isComps ? $board['minExp'] : $mmrNow;
    $bandCall = $isComps ? 'setExp' : 'setMmr';
    $segBtn = fn (bool $on) => 'px-3 sm:px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold whitespace-nowrap transition-colors '.($on ? 'bg-ink text-black' : 'text-ink-muted hover:text-ink');
    $label = 'block text-[10.5px] font-bold uppercase tracking-[.12em] text-ink-subtle mb-1.5';
    $select = 'w-full appearance-none rounded-lg bg-surface-2 border border-line hover:border-line-strong focus:border-gold focus:outline-none pl-3 pr-9 py-2 text-[13px] text-ink cursor-pointer';
    $chev = '<svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    $specLabel = collect($specsSeen)->firstWhere('id', $specNow)['label'] ?? null;
    $compLabel = ($c = collect($compOptions)->firstWhere('key', $compNow)) ? ($c['nick'] ?? collect($c['classes'])->map(fn ($s) => \App\Http\Services\CompLeaderboardService::className($s))->implode(' + ')) : null;
    $chips = array_filter([
        $bandNow ? [($isComps ? 'Experience ' : 'Rating ').$bandNow.'+', $bandCall.'(null)'] : null,
        $specNow && $specLabel ? [$specLabel, 'setSpec(null)'] : null,
        ! $isComps && $compNow && $compLabel ? [$compLabel, 'setComp(null)'] : null,
        $fromNow ? [$regionNames[$fromNow] ?? strtoupper($fromNow), 'setFrom(null)'] : null,
    ]);
@endphp
<div class="linear-card p-3 sm:p-4 mb-3">
    <div class="grid grid-cols-2 {{ $isComps ? 'sm:grid-cols-3' : '' }} gap-3 items-end">
        <div class="col-span-2 {{ $isComps ? 'sm:col-span-3' : '' }}">
            <span class="{{ $label }}" title="{{ $isComps ? 'The team\'s players\' best 3v3 rating ever, averaged' : 'The two teams\' MMR in the game, averaged' }}">{{ $isComps ? 'Experience' : 'Rating' }}</span>
            <div class="inline-flex p-1 rounded-full bg-black/40 border border-line max-w-full overflow-x-auto">
                <button type="button" wire:click="{{ $bandCall }}(null)" class="{{ $segBtn($bandNow === null) }}">Any</button>
                @foreach ($bands as $min => $bandName)
                    <button type="button" wire:click="{{ $bandCall }}({{ $min }})" class="{{ $segBtn($bandNow === $min) }}" title="{{ $bandName }}">{{ $min }}+</button>
                @endforeach
            </div>
        </div>
        <label class="block min-w-0">
            <span class="{{ $label }}">Spec</span>
            <span class="relative block">
                <select class="{{ $select }}" wire:change="setSpec($event.target.value)">
                    <option value="">Any spec</option>
                    @foreach (collect($specsSeen)->groupBy('class') as $class => $specs)
                        <optgroup label="{{ \App\Http\Services\CompLeaderboardService::className((string) $class) }}">
                            @foreach ($specs as $s)
                                <option value="{{ $s['id'] }}" @selected($specNow === $s['id'])>{{ $s['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>{!! $chev !!}
            </span>
        </label>
        <label class="block min-w-0">
            <span class="{{ $label }}">Region</span>
            <span class="relative block">
                <select class="{{ $select }}" wire:change="setFrom($event.target.value || null)">
                    <option value="">Every region</option>
                    @foreach ($regionsSeen as $r => $n)
                        <option value="{{ $r }}" @selected($fromNow === $r)>{{ $regionNames[$r] ?? strtoupper($r) }} ({{ number_format($n) }})</option>
                    @endforeach
                </select>{!! $chev !!}
            </span>
        </label>
        @if ($isComps)
            <label class="block min-w-0 col-span-2 sm:col-span-1">
                <span class="{{ $label }}">Sort by</span>
                <span class="relative block">
                    <select class="{{ $select }}" wire:change="setSort($event.target.value)">
                        @foreach ($sorts as $key => $sortName)
                            <option value="{{ $key }}" @selected($sortNow === $key)>{{ $sortName }}</option>
                        @endforeach
                    </select>{!! $chev !!}
                </span>
            </label>
        @endif
    </div>
    @unless ($isComps)
        {{-- The comps as boxes (2026-10-30, Chriso: "make comp selection the same kind of boxes as what we
             have in 3v3 comps"): the 3v3 Comps page's common picks, each its most-played line-up. The
             most played first; the rest behind More comps. --}}
        <div class="mt-3" x-data="{ more: false }">
            <span class="{{ $label }}">Comp</span>
            <div class="flex flex-wrap gap-2">
                @foreach ($compOptions as $i => $c)
                    @php $on = $compNow === $c['key']; @endphp
                    <button type="button" wire:click="setComp({{ $on ? 'null' : "'".$c['key']."'" }})" @if ($i >= 12 && ! $on) x-show="more" x-cloak @endif
                            class="flex items-center gap-2.5 pl-2 pr-3 py-1.5 rounded-lg border transition-colors {{ $on ? 'border-gold bg-gold-subtle' : 'border-line hover:border-gold/40 hover:bg-surface-2' }}">
                        <span class="flex -space-x-1.5">
                            @forelse ($c['ids'] as $id)
                                @if ($specModels[$id] ?? null) <x-spec-icon :spec="$specModels[$id]" size="w-6 h-6"/> @endif
                            @empty
                                @foreach ($c['classes'] as $slug)
                                    @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-6 h-6"/> @endif
                                @endforeach
                            @endforelse
                        </span>
                        <span class="text-left">
                            <span class="block text-[12px] font-semibold leading-tight {{ $on ? 'text-gold-light' : 'text-ink' }}">{{ $c['nick'] ?? collect($c['classes'])->map(fn ($x) => \App\Http\Services\CompLeaderboardService::className($x))->implode(' + ') }}</span>
                            <span class="block text-[10px] text-ink-muted leading-tight tabular-nums">{{ number_format($c['games']) }} {{ $c['games'] === 1 ? 'game' : 'games' }}</span>
                        </span>
                    </button>
                @endforeach
                @if (count($compOptions) > 12)
                    <button type="button" x-show="!more" @click="more = true" class="px-3 py-1.5 rounded-lg border border-dashed border-line-strong text-[12px] text-ink-muted hover:text-ink">More comps</button>
                @endif
            </div>
        </div>
    @endunless
    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-line text-[12px]">
        <span class="text-ink-muted tabular-nums">
            @if ($isComps)
                {{ number_format(count($board['comps'])) }} {{ count($board['comps']) === 1 ? 'comp' : 'comps' }} from {{ number_format($board['games']) }} games
            @else
                {{ number_format($games['total']) }} {{ $games['total'] === 1 ? 'game' : 'games' }}
            @endif
        </span>
        @foreach ($chips as [$text, $call])
            <button type="button" wire:click="{{ $call }}" class="inline-flex items-center gap-1.5 rounded-full border border-line-gold bg-gold-subtle text-gold-light pl-2.5 pr-2 py-0.5 hover:border-gold" title="Remove this filter">
                {{ $text }} <span aria-hidden="true" class="text-[14px] leading-none">&times;</span>
            </button>
        @endforeach
        @if ($chips !== [] || ($isComps && $sortNow !== 'games'))
            <button type="button" wire:click="clearFilters" class="text-gold hover:text-gold-light ml-1">Clear all</button>
        @endif
    </div>
</div>
