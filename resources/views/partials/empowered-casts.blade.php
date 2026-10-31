{{-- Empowered spells for one spec (docs/class-mechanics.md). Numbers are baked into
     data/empowered-casts by tools/match-review/empowered.php; $empowered is null for a spec without
     enough games. Written for a player: icons first, as few words as will carry it. --}}
@php
    $icon = function (string $name, string $size = 'w-9 h-9', bool $off = false) use ($empowered) {
        $file = $empowered['icons'][$name] ?? null;
        $state = $off ? 'grayscale opacity-20' : '';
        $label = e($name);

        return $file
            ? '<img src="/storage/spell-icons/'.e($file).'" alt="'.$label.'" title="'.$label.'" loading="lazy" class="'.$size.' rounded object-cover flex-shrink-0 '.$state.'">'
            : '<span title="'.$label.'" class="'.$size.' rounded flex items-center justify-center flex-shrink-0 bg-gold-subtle text-gold-light text-[10px] font-bold '.$state.'">'.e(\App\Support\EmpoweredCasts::initials($name)).'</span>';
    };
    $hasIcon = fn (string $name) => isset($empowered['icons'][$name]);
    $ladderMax = $empowered ? max(array_column($empowered['ladder'] ?? [], 'x') ?: [1]) : 1;
@endphp

@if ($empowered)
    <div class="linear-card px-6 py-5">
        <h2 class="font-display text-[17px] text-ink">Empowered spells</h2>
        <div class="grid lg:grid-cols-2 gap-x-10 gap-y-6 mt-3">
            <div>
                <div class="flex items-center gap-2 text-[11px] text-ink-subtle">
                    <span class="w-[116px] text-right">Cast</span><span class="w-4"></span><span class="w-9">Buff</span><span class="w-4"></span><span>Result</span>
                </div>
                @foreach ($empowered['empowers'] as $row)
                    <div class="flex items-center gap-2 py-1.5">
                        <div class="w-[116px] flex items-center justify-end gap-1 flex-shrink-0">
                            @foreach ($row['from'] ?? [] as $i => $from)
                                @if ($i && ($row['chain'] ?? false))
                                    <span class="text-ink-subtle">&rarr;</span>
                                @endif
                                {!! $icon($from) !!}
                            @endforeach
                        </div>
                        <span class="w-4 text-ink-subtle">@if ($row['from'] ?? [])&rarr;@endif</span>
                        <span class="flex items-center gap-2 flex-shrink-0">{!! $icon($row['buff']) !!}</span>
                        <span class="w-4 text-ink-subtle">&rarr;</span>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 min-w-0">
                            @foreach ($row['results'] as $result)
                                @if (isset($result['bolts']))
                                    <span class="flex items-center gap-1">
                                        @for ($i = 0; $i < $result['bolts']; $i++)<i class="w-2.5 h-2.5 rounded-full bg-ink-muted"></i>@endfor
                                        <b class="text-gold-light mx-0.5">+</b>
                                        @for ($i = 0; $i < $result['extra']; $i++)<i class="w-2.5 h-2.5 rounded-full bg-gold-light"></i>@endfor
                                        <span class="text-[11.5px] text-ink-muted ml-1.5">{{ $result['label'] }}</span>
                                    </span>
                                @else
                                    <span class="flex items-center gap-1.5">
                                        {!! $icon($result['ability'], 'w-6 h-6') !!}
                                        @unless ($hasIcon($result['ability']))<span class="text-[11.5px] text-ink-muted">{{ $result['ability'] }}</span>@endunless
                                        <b class="font-display text-[16px] text-gold-light">&times;{{ number_format($result['x'], 2) }}</b>
                                        @isset($result['label'])<span class="text-[11.5px] text-ink-muted">{{ $result['label'] }}</span>@endisset
                                    </span>
                                @endif
                            @endforeach
                            @unless ($hasIcon($row['buff']))<span class="text-[11.5px] text-ink-muted">{{ $row['buff'] }}</span>@endunless
                        </div>
                    </div>
                @endforeach
            </div>

            @if (! empty($empowered['ladder']) && count($empowered['ladder']) > 1)
                <div>
                    <p class="text-[12.5px] font-semibold text-ink mb-1.5">{{ $empowered['payoff'] }} {{ $empowered['kind'] }} by buffs</p>
                    <div class="flex items-center gap-2 text-[11px] text-ink-subtle mb-1">
                        <span class="{{ count($empowered['stacked']) === 3 ? 'w-[92px]' : (count($empowered['stacked']) === 2 ? 'w-[60px]' : 'w-7') }} flex-shrink-0">Buffs up</span>
                        <span class="flex-1">{{ ucfirst($empowered['kind']) }} per {{ $empowered['ladderUnit'] ?? 'hit' }}</span>
                    </div>
                    @foreach ($empowered['ladder'] as $rung)
                        <div class="flex items-center gap-2 py-0.5">
                            @foreach ($empowered['stacked'] as $i => $buff)
                                {!! $icon($buff, 'w-7 h-7', ! ($rung['up'][$i] ?? 0)) !!}
                            @endforeach
                            <div class="flex-1 h-3 rounded-full bg-surface-3 overflow-hidden">
                                <div class="h-full rounded-full bg-gold" style="width: {{ round($rung['x'] / $ladderMax * 100) }}%"></div>
                            </div>
                            <b class="w-12 text-right text-[13.5px] text-ink">&times;{{ number_format($rung['x'], 2) }}</b>
                        </div>
                    @endforeach
                    <p class="text-[11px] text-ink-subtle mt-1.5">Other buffs may also be up in these bars.</p>
                </div>
            @endif
        </div>
        <p class="text-[11.5px] text-ink-subtle mt-3">
            Measured from {{ number_format($empowered['rounds']) }} rounds by {{ number_format($empowered['players']) }} players{{ ! empty($empowered['patch']) ? ' on patch '.$empowered['patch'] : '' }}. &times;1.20 means 20% bigger than without the buff.
            @if (! empty($empowered['note'])){{ $empowered['note'] }}@endif
        </p>
        @if ($empowered['stale'] ?? false)
            <p class="text-[12px] text-gold-light mt-1.5">The game has been patched since, so some of these may have changed.</p>
        @endif
    </div>
@else
    <div class="linear-card px-6 py-4">
        <h2 class="font-display text-[17px] text-ink">Empowered spells</h2>
        <p class="text-[12.5px] text-ink-muted mt-1">None confirmed for {{ $spec?->name }} {{ $class?->name }} yet. A buff is listed once its tooltip and our games agree.</p>
    </div>
@endif
