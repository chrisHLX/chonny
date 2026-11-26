{{-- WoW Forever's Gear Finder (/wow/forever/gear, 2026-11-27): the best weapons and armour for a level, by the
     stats the player picks. ForeverGearFinderController reads the form (a plain GET, so a search is a link),
     ForeverGearFinder filters and ranks the addon's sweep and adds where each item comes from. Rows, not a
     table, so a phone reads them. --}}
@php
    use App\Http\Services\ForeverGearFinder;
    use App\Support\ForeverRecipes;

    $money = function (int $c): string {
        $g = intdiv($c, 10000);
        $s = intdiv($c % 10000, 100);
        $cp = $c % 100;
        if ($g > 0) {
            return number_format($g).'g'.($s ? ' '.$s.'s' : '');
        }

        return $s > 0 ? $s.'s'.($cp ? ' '.$cp.'c' : '') : $cp.'c';
    };
    $num = fn (float $v): string => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $qualityColour = [0 => '#9d9d9d', 1 => '#e8e8e8', 2 => '#1eff00', 3 => '#0070dd', 4 => '#a335ee', 5 => '#ff8000', 6 => '#e6cc80'];
    $qualityName = [0 => 'Poor', 1 => 'Common', 2 => 'Uncommon', 3 => 'Rare', 4 => 'Epic'];
    $bindText = [1 => 'Binds when picked up', 2 => 'Binds when equipped', 3 => 'Binds when used', 4 => 'Quest item'];
    $typeName = fn (array $i): ?string => $i['class'] === 4 ? (ForeverGearFinder::ARMOUR[$i['sub']] ?? null) : ($i['class'] === 2 ? (ForeverGearFinder::WEAPONS[$i['sub']] ?? null) : null);
    $cost = function (array $v) use ($money): string {
        $parts = $v['price'] > 0 ? [$money($v['price'])] : [];
        foreach ($v['costs'] as [$count, $what]) {
            $parts[] = number_format($count).' '.(is_int($what) ? (ForeverRecipes::name($what) ?? 'item #'.$what) : $what);
        }

        return implode(' + ', $parts) ?: 'free';
    };
    // "Crit 2%", "Agility 12"; a stat the item lacks is left out
    $statText = function (string $key, float $v) use ($num): string {
        if ($v <= 0) {
            return '';
        }

        return in_array($key, ['crit', 'hit'], true)
            ? ucfirst($key).' '.$num($v).'%'
            : ForeverGearFinder::STATS[$key][0].' '.$num($v);
    };
    $picked = $f['stats'];
    $found = array_sum(array_map('count', $results));
@endphp
<x-app-layout title="Gear Finder for WoW Forever: the Best Gear for Your Level" description="Find the best WoW Forever weapons and armour for your level, ranked by the stats you choose, with auction house prices and where each item comes from.">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10">

    <section class="page-hero">
        <p class="page-eyebrow">WoW Forever · Gear</p>
        <h1 class="page-title">Gear Finder</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl leading-relaxed">
            The best weapons and armour for your level, ranked by the stats you pick. Every item is read from the game
            itself by the MindCollector app's Auction House addon.
        </p>
    </section>

    @if ($itemCount === 0)
        <div class="mt-8 linear-card p-6 text-[14px] text-ink-muted leading-relaxed">
            The item list hasn't arrived yet. It comes from the
            <a href="{{ route('wow.forever') }}#prices" class="text-gold hover:underline">Auction House addon</a>,
            and this page fills in once a player's app sends it.
        </div>
    @else
        <form method="GET" action="{{ route('wow.forever.gear') }}" class="mt-6 linear-card p-5 space-y-5"
              x-data="{ picked: @js(array_keys($picked)) }">
            <input type="hidden" name="f" value="1">

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <label class="block">
                    <span class="text-[12px] font-semibold text-ink-muted">Your level</span>
                    <input type="number" name="level" min="1" max="60" value="{{ $f['level'] }}" class="form-input mt-1">
                </label>
                <label class="block">
                    <span class="text-[12px] font-semibold text-ink-muted">Class</span>
                    <select name="class" class="form-select mt-1">
                        <option value="">Any class</option>
                        @foreach ($classes as $c)
                            <option value="{{ $c }}" @selected($f['class'] === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[12px] font-semibold text-ink-muted">Slot</span>
                    <select name="slot" class="form-select mt-1">
                        <option value="">Every slot, best {{ 5 }} each</option>
                        @foreach (array_keys(ForeverGearFinder::SLOTS) as $s)
                            <option value="{{ $s }}" @selected($f['slot'] === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[12px] font-semibold text-ink-muted">Lowest quality</span>
                    <select name="quality" class="form-select mt-1">
                        @foreach ($qualityName as $q => $name)
                            <option value="{{ $q }}" @selected($f['quality'] === $q)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <fieldset>
                <legend class="text-[12px] font-semibold text-ink-muted">Stats you want, and how much each counts</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach (ForeverGearFinder::STATS as $key => [$label])
                        <div class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[13px] transition-colors"
                             :class="picked.includes(@js($key)) ? 'border-line-gold bg-surface-3 text-ink' : 'border-line text-ink-muted'">
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" name="pick[]" value="{{ $key }}" x-model="picked" class="sr-only">
                                {{ $label }}
                            </label>
                            <input type="number" name="w[{{ $key }}]" value="{{ $num($picked[$key] ?? 1) }}" min="0" max="100" step="0.1"
                                   x-show="picked.includes(@js($key))" x-cloak :disabled="! picked.includes(@js($key))"
                                   class="w-12 bg-transparent border-0 border-b border-line p-0 text-center text-[13px] text-gold focus:ring-0"
                                   aria-label="How much {{ $label }} counts">
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-[12px] text-ink-subtle">
                    An item's score is the sum of the stats you pick, each times its number. Hit and crit count as the % the
                    tooltip shows.
                </p>
            </fieldset>

            <details class="group" @if ($f['armour'] !== array_keys(ForeverGearFinder::ARMOUR) || $f['weapons'] !== array_keys(ForeverGearFinder::WEAPONS)) open @endif>
                <summary class="cursor-pointer text-[13px] font-semibold text-ink-muted hover:text-ink">Armour and weapon types</summary>
                <div class="mt-3 space-y-3">
                    <div class="flex flex-wrap gap-x-4 gap-y-2 text-[13px] text-ink-muted">
                        @foreach (ForeverGearFinder::ARMOUR as $id => $name)
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="armour[]" value="{{ $id }}" @checked(in_array($id, $f['armour'], true)) class="form-checkbox">
                                {{ $name }}
                            </label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-2 text-[13px] text-ink-muted">
                        @foreach (ForeverGearFinder::WEAPONS as $id => $name)
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="weapons[]" value="{{ $id }}" @checked(in_array($id, $f['weapons'], true)) class="form-checkbox">
                                {{ $name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </details>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px] text-ink-muted">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="far" value="1" @checked($f['far']) class="form-checkbox">
                        Include items with no level requirement that are well above your level
                    </label>
                    @if (count($markets) > 1)
                        <label class="inline-flex items-center gap-2">
                            Prices from
                            <select name="market" class="form-select !w-auto !py-1">
                                @foreach ($markets as $m)
                                    <option value="{{ $m['key'] }}" @selected(($market['key'] ?? null) === $m['key'])>
                                        {{ $m['faction'] === 'Neutral' ? 'Neutral (goblin AH)' : $m['faction'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
                <button type="submit" class="btn-primary">Find gear</button>
            </div>
        </form>

        @if ($picked === [])
            <p class="mt-6 text-[14px] text-ink-muted">Pick at least one stat to see the best gear for it.</p>
        @elseif ($found === 0)
            <p class="mt-6 text-[14px] text-ink-muted">Nothing matches. Try a higher level, a lower quality, or more armour and weapon types.</p>
        @else
            @foreach ($results as $slot => $items)
                <section class="mt-6">
                    <h2 class="text-[13px] font-bold text-ink-subtle uppercase tracking-[.12em]">{{ $slot }}</h2>
                    <div class="mt-2 linear-card overflow-hidden">
                        @foreach ($items as $i)
                            @php
                                $type = $typeName($i);
                                $sources = [];
                                if ($i['ah']) {
                                    $sources[] = 'On the AH: '.$money($i['ah']['now']).' at the last scan'
                                        .($i['ah']['seen'] >= 3 ? ', usually '.$money($i['ah']['usual']) : '');
                                }
                                foreach (array_slice($i['vendors'], 0, 2) as $v) {
                                    $sources[] = 'Sold by '.$v['name'].($v['zone'] ? ' ('.$v['zone'].')' : '').': '.$cost($v);
                                }
                                if ($i['made']) {
                                    $sources[] = 'Crafted: '.$i['made'];
                                }
                                if ($sources === []) {
                                    $sources[] = $i['bind'] === 1 ? 'A drop or quest reward (binds when picked up)' : 'Not on the AH this week';
                                }
                            @endphp
                            <div class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 px-5 py-3 border-b border-line last:border-b-0">
                                <div class="min-w-0">
                                    <p class="text-[14px] font-semibold" style="color: {{ $qualityColour[$i['q']] ?? '#e8e8e8' }}">{{ $i['name'] }}</p>
                                    <p class="text-[12px] text-ink-subtle mt-0.5">
                                        {{ $i['req'] > 0 ? 'Level '.$i['req'] : 'No level requirement' }}
                                        · item level {{ $i['ilvl'] }}
                                        @if ($type) · {{ $type }} @endif
                                        @if (isset($bindText[$i['bind']])) · {{ $bindText[$i['bind']] }} @endif
                                        @if ($i['unique']) · Unique @endif
                                    </p>
                                </div>
                                <p class="text-[15px] font-bold text-gold text-right whitespace-nowrap" title="Score">{{ $num($i['score']) }}</p>
                                <p class="col-span-2 text-[12px] text-ink-muted">
                                    {{ implode(' · ', array_filter(array_map($statText, array_keys($i['values']), $i['values']))) }}
                                </p>
                                <p class="col-span-2 text-[12px] text-ink-subtle">{{ implode(' · ', $sources) }}</p>
                                @if ($i['requires'])
                                    <p class="col-span-2 flex flex-wrap gap-1.5">
                                        @foreach ($i['requires'] as $r)
                                            <span class="badge-amber">{{ $r }}</span>
                                        @endforeach
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

        <div class="mt-6 text-[12px] text-ink-subtle leading-relaxed space-y-2 max-w-3xl">
            <p>
                {{ number_format($itemCount) }} weapons and armour pieces, read from the game by the Auction House addon.
                Auction prices are the cheapest listing at the last scan of the week, and the middle of the week's scans
                when there are three or more.
            </p>
            <p>
                Many items that drop from bosses have no level requirement, though they come from level 60 dungeons. Items
                with no requirement more than {{ ForeverGearFinder::NO_REQ_SPAN }} item levels above yours are hidden
                unless you tick the box. Where an item drops isn't known yet.
            </p>
        </div>
    @endif
</div>
</x-app-layout>
