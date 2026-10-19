{{-- Every uploaded game, for an admin (App\Livewire\Admin\Matches). --}}
@php
    $color = fn ($class) => $classColors[$class] ?? '#B3B3B3';
    $clock = fn ($s) => $s ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '';
    $peak = max(1, $daily->max('games'));
    $filtered = $uploader || $bracket !== '' || $period !== 'all' || $result !== '' || trim($search) !== '';
@endphp
<div class="min-h-full py-8 px-4 sm:px-6 lg:px-10 xl:px-16">
    <div class="max-w-7xl mx-auto space-y-5">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">Matches</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">
                Every game uploaded to the site, by everyone. Played times are the player's own clock, as their combat log wrote it.
            </p>
        </div>

        {{-- ── Totals ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach ([
                ['Games', $summary['games'], number_format($summary['rounds']).' rounds'],
                ['Last 24 hours', $summary['day'], 'games played'],
                ['Last 7 days', $summary['week'], 'games played'],
                ['Rounds won', $summary['won'], number_format($summary['lost']).' lost'],
                ['Uploaders', $summary['uploaders'], $summary['connected'].' with the app connected'],
                ['Rounds uploaded', $summary['uploadedToday'], 'in the last 24 hours'],
            ] as [$label, $value, $sub])
                <div class="linear-card p-4">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider">{{ $label }}</p>
                    <p class="text-[24px] font-semibold text-ink leading-none tabular-nums mt-2">{{ number_format($value) }}</p>
                    <p class="text-[11px] text-ink-muted mt-1.5">{{ $sub }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Games per day ── --}}
        <div class="linear-card p-5">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <p class="text-[12px] font-medium text-ink">Games played per day, last 30 days</p>
                <p class="text-[11px] text-ink-subtle tabular-nums">most in a day: {{ $daily->max('games') }}</p>
            </div>
            <div class="flex items-end gap-[2px] h-28 border-b border-line">
                @foreach ($daily as $d)
                    <div class="flex-1 h-full flex flex-col justify-end group relative">
                        @if ($d['games'] > 0)
                            <div class="w-full rounded-t bg-gold group-hover:bg-gold-light transition-colors"
                                 style="height: {{ max(3, round(100 * $d['games'] / $peak)) }}%"></div>
                        @endif
                        <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block whitespace-nowrap rounded-md bg-surface-3 px-2 py-1 text-[11px] text-ink shadow-lg z-10">
                            {{ $d['label'] }} <span class="text-ink-muted">&middot;</span> <span class="tabular-nums">{{ $d['games'] }}</span> {{ $d['games'] === 1 ? 'game' : 'games' }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[10px] text-ink-subtle mt-1.5">
                <span>{{ $daily->first()['label'] }}</span>
                <span>{{ $daily->last()['label'] }}</span>
            </div>
        </div>

        {{-- ── Who uploads ── --}}
        <div class="linear-card p-5">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <p class="text-[12px] font-medium text-ink">Uploaders</p>
                <p class="text-[11px] text-ink-subtle">Click a player to show only their games.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12px] min-w-[980px]">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left whitespace-nowrap">
                            <th class="font-medium py-1.5 pr-3">Player</th>
                            <th class="font-medium py-1.5 px-3">Characters</th>
                            <th class="font-medium py-1.5 px-3 text-right">Games</th>
                            <th class="font-medium py-1.5 px-3 text-right">Rounds W&ndash;L</th>
                            <th class="font-medium py-1.5 px-3">Brackets</th>
                            <th class="font-medium py-1.5 px-3">Last played</th>
                            <th class="font-medium py-1.5 px-3">Last upload (UTC)</th>
                            <th class="font-medium py-1.5 pl-3">App</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($uploaders as $u)
                            <tr wire:click="showUploader({{ $u['id'] }})"
                                @class(['cursor-pointer hover:bg-surface-2 transition-colors', 'bg-surface-2' => $uploader === $u['id']])>
                                <td class="py-2 pr-3">
                                    <span class="text-ink font-medium">{{ $u['name'] }}</span>
                                    <span class="block text-[11px] text-ink-subtle">{{ $u['email'] }} &middot; #{{ $u['id'] }}</span>
                                </td>
                                <td class="py-2 px-3 text-ink-muted">{{ implode(', ', array_slice($u['characters'], 0, 4)) ?: '-' }}</td>
                                <td class="py-2 px-3 text-right text-ink tabular-nums">{{ number_format($u['games']) }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">
                                    <span class="text-ink">{{ $u['won'] }}</span><span class="text-ink-subtle">&ndash;</span><span class="text-ink-muted">{{ $u['lost'] }}</span>
                                </td>
                                <td class="py-2 px-3 text-ink-muted">
                                    @foreach ($u['brackets'] as $b)
                                        <span class="whitespace-nowrap">{{ str_replace('Rated Solo ', '', $b['bracket']) }} <span class="text-ink tabular-nums">{{ $b['n'] }}</span></span>@if (! $loop->last)<span class="text-ink-subtle"> &middot; </span>@endif
                                    @endforeach
                                </td>
                                <td class="py-2 px-3 text-ink-muted whitespace-nowrap">{{ $u['lastPlayed'] ? \Carbon\Carbon::parse($u['lastPlayed'])->format('j M H:i') : '-' }}</td>
                                <td class="py-2 px-3 whitespace-nowrap">
                                    @if ($u['lastUpload'])
                                        <span class="text-ink-muted">{{ \Carbon\Carbon::parse($u['lastUpload'])->diffForHumans() }}</span>
                                    @else
                                        <span class="text-amber-300">nothing yet</span>
                                    @endif
                                </td>
                                <td class="py-2 pl-3 whitespace-nowrap">
                                    @if ($u['connected'])
                                        <span class="badge-green" title="Key made {{ $u['connected'] }} UTC">connected</span>
                                    @else
                                        <span class="badge-gray">browser only</span>
                                    @endif
                                    @unless ($u['pooled'])
                                        <span class="badge-gray" title="Their games are kept out of the shared averages">opted out</span>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-3 text-ink-subtle">Nobody has uploaded a game yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── The games ── --}}
        <div class="linear-card p-5">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Player name"
                       class="form-input !w-44 !py-1.5 !text-[12px]">
                <select wire:model.live="uploader" class="form-select !w-auto !py-1.5 !text-[12px]">
                    <option value="">Every uploader</option>
                    @foreach ($uploaders->where('games', '>', 0) as $u)
                        <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                    @endforeach
                </select>
                <select wire:model.live="bracket" class="form-select !w-auto !py-1.5 !text-[12px]">
                    <option value="">Every bracket</option>
                    @foreach ($brackets as $b)
                        <option value="{{ $b }}">{{ $b }}</option>
                    @endforeach
                </select>
                <select wire:model.live="period" class="form-select !w-auto !py-1.5 !text-[12px]">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="result" class="form-select !w-auto !py-1.5 !text-[12px]">
                    <option value="">Won or lost</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                    <option value="even">Even (shuffle 3&ndash;3)</option>
                </select>
                @if ($filtered)
                    <button type="button" wire:click="clearFilters" class="btn-ghost !py-1.5 !text-[12px]">Clear</button>
                @endif
                <span class="ml-auto text-[11px] text-ink-subtle tabular-nums">{{ number_format($page->total()) }} {{ $page->total() === 1 ? 'game' : 'games' }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[12px] min-w-[1040px]">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left whitespace-nowrap">
                            <th class="font-medium py-1.5 pr-3">Played</th>
                            <th class="font-medium py-1.5 px-3">Uploader</th>
                            <th class="font-medium py-1.5 px-3">Bracket</th>
                            <th class="font-medium py-1.5 px-3">Result</th>
                            <th class="font-medium py-1.5 px-3">Their character</th>
                            <th class="font-medium py-1.5 px-3">With</th>
                            <th class="font-medium py-1.5 px-3">Against</th>
                            <th class="font-medium py-1.5 px-3 text-right">MMR</th>
                            <th class="font-medium py-1.5 pl-3 text-right">Length</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($games as $g)
                            @php
                                $outcome = $g['won'] > $g['lost'] ? 'won' : ($g['won'] < $g['lost'] ? 'lost' : 'even');
                            @endphp
                            <tr wire:key="game-{{ $g['id'] }}" wire:click="toggle({{ $g['id'] }})"
                                @class(['cursor-pointer hover:bg-surface-2 transition-colors align-top', 'bg-surface-2' => $open === $g['id']])>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <span class="text-ink">{{ $g['playedAt']?->format('D j M H:i') }}</span>
                                    <span class="block text-[11px] text-ink-subtle" title="Uploaded {{ $g['uploadedAt'] }} UTC">uploaded {{ $g['uploadedAt']?->diffForHumans() }}</span>
                                </td>
                                <td class="py-2 px-3 text-ink-muted whitespace-nowrap">{{ $g['uploader'] }}</td>
                                <td class="py-2 px-3 text-ink-muted whitespace-nowrap">{{ str_replace('Rated Solo ', '', $g['bracket']) }}</td>
                                <td class="py-2 px-3 whitespace-nowrap">
                                    @if ($g['shuffle'])
                                        <span @class(['font-medium tabular-nums', 'text-green-400' => $outcome === 'won', 'text-red-400' => $outcome === 'lost', 'text-ink-muted' => $outcome === 'even'])>{{ $g['won'] }}&ndash;{{ $g['lost'] }}</span>
                                    @else
                                        <span @class(['font-medium', 'text-green-400' => $outcome === 'won', 'text-red-400' => $outcome === 'lost', 'text-ink-muted' => $outcome === 'even'])>{{ $outcome === 'won' ? 'Won' : ($outcome === 'lost' ? 'Lost' : 'Draw') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <span class="text-ink font-medium" title="{{ $g['you']['full'] }}">{{ $g['you']['name'] }}</span>
                                    @if ($g['you']['spec'])
                                        <span class="block text-[11px]" style="color: {{ $color($g['you']['class']) }}">{{ $g['you']['spec'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    @if ($g['shuffle'])
                                        <span class="text-[11px] text-ink-subtle">changes each round</span>
                                    @endif
                                    @foreach ($g['allies'] as $p)
                                        <span class="block text-[11px] whitespace-nowrap" style="color: {{ $color($p['class']) }}" title="{{ $p['full'] }}">{{ $p['spec'] ?? $p['name'] }}</span>
                                    @endforeach
                                </td>
                                <td class="py-2 px-3">
                                    @foreach ($g['enemies'] as $p)
                                        <span class="block text-[11px] whitespace-nowrap" style="color: {{ $color($p['class']) }}" title="{{ $p['full'] }}">{{ $p['spec'] ?? $p['name'] }}</span>
                                    @endforeach
                                </td>
                                <td class="py-2 px-3 text-right whitespace-nowrap tabular-nums">
                                    @if ($g['mmrUs'] !== null)
                                        <span class="text-ink">{{ $g['mmrUs'] }}</span>
                                        <span class="block text-[11px] text-ink-subtle">vs {{ $g['mmrThem'] ?? '?' }}</span>
                                    @else
                                        <span class="text-ink-subtle">-</span>
                                    @endif
                                </td>
                                <td class="py-2 pl-3 text-right text-ink-muted tabular-nums whitespace-nowrap">{{ $clock($g['seconds']) }}</td>
                            </tr>

                            @if ($g['detail'])
                                <tr wire:key="detail-{{ $g['id'] }}" class="bg-surface-0">
                                    <td colspan="9" class="p-4">
                                        <div class="flex flex-wrap items-center gap-3 mb-3">
                                            @if ($g['card'])
                                                <a href="{{ route('admin.matches.card', ['user' => $g['userId'], 'file' => $g['lobby'].'.html']) }}" target="_blank" rel="noopener"
                                                   class="btn-primary !py-1.5 !px-4 !text-[12px]">Open the game card</a>
                                            @else
                                                <span class="text-[11px] text-ink-subtle">No game card built for this one yet.</span>
                                            @endif
                                            <span class="text-[11px] text-ink-subtle">Game {{ $g['lobby'] }} &middot; uploaded {{ $g['uploadedAt']?->format('j M H:i') }} UTC</span>
                                        </div>

                                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                                            <div class="overflow-x-auto">
                                                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Rounds</p>
                                                <table class="w-full text-[12px] min-w-[420px]">
                                                    <thead>
                                                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left whitespace-nowrap">
                                                            <th class="font-medium py-1 pr-2">#</th>
                                                            <th class="font-medium py-1 px-2">Result</th>
                                                            @if ($g['shuffle'])<th class="font-medium py-1 px-2">With</th>@endif
                                                            <th class="font-medium py-1 px-2">Died</th>
                                                            <th class="font-medium py-1 px-2 text-right">MMR</th>
                                                            <th class="font-medium py-1 pl-2 text-right">Length</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-line">
                                                        @foreach ($g['detail']['rounds'] as $r)
                                                            <tr>
                                                                <td class="py-1 pr-2 text-ink-subtle tabular-nums">{{ $r['sequence'] }}</td>
                                                                <td @class(['py-1 px-2 font-medium', 'text-green-400' => $r['result'] === 'won', 'text-red-400' => $r['result'] === 'lost', 'text-ink-muted' => ! in_array($r['result'], ['won', 'lost'])])>{{ ucfirst($r['result'] ?? '?') }}</td>
                                                                @if ($g['shuffle'])
                                                                    <td class="py-1 px-2">
                                                                        @foreach ($r['with'] as $p)
                                                                            <span class="text-[11px] whitespace-nowrap" style="color: {{ $color($p['class']) }}" title="{{ $p['full'] }}">{{ $p['name'] }}</span>@if (! $loop->last)<span class="text-ink-subtle">, </span>@endif
                                                                        @endforeach
                                                                    </td>
                                                                @endif
                                                                <td class="py-1 px-2 text-ink-muted">{{ $r['died'] ?? '-' }}</td>
                                                                <td class="py-1 px-2 text-right text-ink-muted tabular-nums whitespace-nowrap">{{ $r['mmrUs'] !== null ? $r['mmrUs'].' vs '.($r['mmrThem'] ?? '?') : '-' }}</td>
                                                                <td class="py-1 pl-2 text-right text-ink-muted tabular-nums">{{ $clock($r['seconds']) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="overflow-x-auto">
                                                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Players, whole game</p>
                                                <table class="w-full text-[12px] min-w-[440px]">
                                                    <thead>
                                                        <tr class="text-[10px] uppercase tracking-wide text-ink-subtle text-left whitespace-nowrap">
                                                            <th class="font-medium py-1 pr-2">Player</th>
                                                            <th class="font-medium py-1 px-2 text-right">Item level</th>
                                                            <th class="font-medium py-1 px-2 text-right">Damage</th>
                                                            <th class="font-medium py-1 px-2 text-right">Healing</th>
                                                            <th class="font-medium py-1 pl-2 text-right">Deaths</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-line">
                                                        @foreach ($g['detail']['players'] as $p)
                                                            <tr>
                                                                <td class="py-1 pr-2 whitespace-nowrap">
                                                                    <span @class(['text-ink', 'font-semibold' => $p['isYou']]) title="{{ $p['full'] }}">{{ $p['name'] }}</span>
                                                                    <span class="text-[11px]" style="color: {{ $color($p['class']) }}">{{ $p['spec'] }}</span>
                                                                    @if ($p['isYou'])<span class="text-[10px] text-gold">uploader</span>@endif
                                                                </td>
                                                                <td class="py-1 px-2 text-right text-ink-muted tabular-nums">{{ $p['ilvl'] ?? '-' }}</td>
                                                                <td class="py-1 px-2 text-right text-ink-muted tabular-nums">{{ number_format($p['damage'] / 1000000, 1) }}M</td>
                                                                <td class="py-1 px-2 text-right text-ink-muted tabular-nums">{{ number_format($p['healing'] / 1000000, 1) }}M</td>
                                                                <td class="py-1 pl-2 text-right text-ink-muted tabular-nums">{{ $p['deaths'] }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="9" class="py-4 text-ink-subtle">No games match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $page->links() }}
            </div>
        </div>
    </div>
</div>
