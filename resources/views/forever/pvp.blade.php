{{-- WoW Forever's PvP Guides (/wow/forever/pvp/{class}, 2026-11-23). Each class's abilities from the game's
     own tables, grouped like retail's Spells table (components/spells/table.blade.php): what you press, then
     buffs and passives, each by category. No talent builds yet: an ability a talent gives is marked.
     Built by `forever:abilities`; see ForeverPvpController and docs/forever/pvp-guides.md. --}}
@php
    $groupLabel = ['active' => 'Active Abilities', 'passive' => 'Buffs & Passives'];
    $badge = config('spell_display.category_badges');
    $accent = [
        'Crowd Control' => 'text-violet',
        'Defensive' => 'text-rose-400',
        'Mobility' => 'text-sky-400',
        'Utility' => 'text-amber-400',
        'Offensive' => 'text-orange-400',
        'Other' => 'text-ink-subtle',
    ];
    $secs = fn (float $s) => rtrim(rtrim(number_format($s, 1), '0'), '.');
    $cooldown = function (?float $s) use ($secs): ?string {
        if ($s === null) {
            return null;
        }
        if ($s >= 60 && fmod($s, 60) == 0) {
            return ($s / 60).' min';
        }

        return $secs($s).' sec';
    };
    $tabs = collect(\App\Support\ForeverAbilities::CATEGORIES)->filter(fn ($c) => isset($counts[$c]))->values();
    $classIcon = fn (string $slug) => '/storage/class-icons/classicon_'.$slug.'.jpg';
    // One row per ability for the filters: [category, lower-case name, passive].
    $index = collect($groups)->flatMap(fn ($cats, $g) => collect($cats)->flatMap(fn ($abs, $cat) => collect($abs)->map(fn ($a) => [$cat, mb_strtolower($a['name']), $g === 'passive'])))->values();
@endphp
<x-app-layout :title="$class['name'].' Abilities in WoW Forever: PvP Guide'" :description="'Every '.$class['name'].' ability in WoW Forever, sorted into crowd control, defensives, mobility, utility and damage, with cooldowns, from the game\'s own data.'">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10"
     x-data="{
        filter: 'all',
        search: '',
        rows: [],
        shows(cat, name, passive) {
            const f = this.filter;
            const inFilter = f === 'all' || (f === 'passive' ? passive : (!passive && f === cat));
            return inFilter && (this.search === '' || name.includes(this.search.toLowerCase()));
        },
        any(cat, passive) { return this.rows.some(r => (cat === null || r[0] === cat) && (passive === null || r[2] === passive) && this.shows(r[0], r[1], r[2])); },
     }"
     x-init="rows = @js($index)">

    <section class="page-hero">
        <p class="page-eyebrow">WoW Forever · PvP Guides</p>
        <h1 class="page-title">{{ $class['name'] }} Abilities</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl leading-relaxed">
            Every {{ $class['name'] }} ability in WoW Forever, sorted by what it does in a fight. Cooldowns are before talents.
        </p>
    </section>

    {{-- Class picker --}}
    <nav class="mt-6 flex flex-wrap gap-2" aria-label="Classes">
        @foreach ($classes as $c)
            @php $cColor = config('wow_classes.colors')[$c['slug']] ?? '#8A8A9A'; $on = $c['slug'] === $class['slug']; @endphp
            <a href="{{ route('wow.forever.pvp', $c['slug']) }}" wire:navigate
               class="flex items-center gap-2 rounded-full pl-1 pr-3.5 py-1 text-[13px] font-semibold transition-colors {{ $on ? 'bg-surface-3 text-ink' : 'text-ink-muted hover:text-ink hover:bg-surface-2' }}"
               style="{{ $on ? 'box-shadow: inset 0 0 0 1px '.$cColor.'99' : '' }}"
               @if ($on) aria-current="page" @endif>
                <img src="{{ $classIcon($c['slug']) }}" alt="" class="w-7 h-7 rounded-full object-cover border" style="border-color: {{ $cColor }}66">
                {{ $c['name'] }}
            </a>
        @endforeach
    </nav>

    {{-- Filters and search --}}
    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1 linear-card !hover:border-line p-1">
            <button type="button" @click="filter = 'all'" class="tab-btn" :class="filter === 'all' ? 'tab-active' : 'tab-inactive'">All</button>
            @foreach ($tabs as $tab)
                <button type="button" @click="filter = @js($tab)" class="tab-btn" :class="filter === @js($tab) ? 'tab-active' : 'tab-inactive'">{{ $tab }}</button>
            @endforeach
            @isset ($groups['passive'])
                <button type="button" @click="filter = 'passive'" class="tab-btn" :class="filter === 'passive' ? 'tab-active' : 'tab-inactive'">Buffs &amp; Passives</button>
            @endisset
        </div>
        <div class="relative w-full sm:w-56">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink-subtle pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" x-model="search" placeholder="Search abilities…" class="form-input !pl-9" aria-label="Search abilities">
        </div>
    </div>

    {{-- The abilities: rows, not a wide table, so a phone reads them without scrolling sideways. --}}
    <div class="mt-4 linear-card overflow-hidden">
        @foreach ($groups as $groupKey => $categories)
            @php $passive = $groupKey === 'passive'; @endphp
            <div x-show="any(null, {{ $passive ? 'true' : 'false' }})">
                <div class="px-5 py-2 bg-surface-3 border-b border-line-gold text-[12px] uppercase tracking-wide font-bold text-ink">
                    {{ $groupLabel[$groupKey] }}
                </div>
                @foreach ($categories as $category => $abilities)
                    <div x-show="any(@js($category), {{ $passive ? 'true' : 'false' }})">
                        <div class="px-5 py-1.5 bg-surface-2 border-b border-line-strong text-[10px] uppercase tracking-wide font-semibold {{ $accent[$category] ?? 'text-gold' }}">
                            {{ $category }}
                        </div>
                        @foreach ($abilities as $a)
                            @php
                                $cast = $a['cast'] > 0 ? $secs($a['cast']).' sec cast' : 'Instant';
                                $meta = array_filter([
                                    $cast,
                                    $a['range'] ? $secs($a['range']).' yd' : null,
                                    $a['cost'],
                                    $a['level'] ? 'Level '.$a['level'] : null,
                                ]);
                                $cd = $cooldown($a['cooldown']);
                            @endphp
                            <div class="grid grid-cols-[2.5rem_1fr] sm:grid-cols-[2.5rem_14rem_1fr_6rem] gap-x-3 gap-y-1 px-5 py-3 border-b border-line last:border-b-0 hover:bg-surface-2/50 transition-colors"
                                 x-show="shows(@js($category), @js(mb_strtolower($a['name'])), {{ $passive ? 'true' : 'false' }})">
                                <img src="/storage/forever-icons/{{ $a['icon'] }}.jpg" alt="" loading="lazy" class="w-9 h-9 rounded border border-line object-cover row-span-2 sm:row-span-1">
                                <div class="min-w-0">
                                    <p class="text-[13px] font-semibold text-ink">{{ $a['name'] }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span class="{{ $badge[$category] ?? 'badge-gray' }}">{{ $category }}</span>
                                        @if ($a['talent'])
                                            <span class="badge-blue" title="A talent in {{ $a['tree'] }} gives this ability.">Talent</span>
                                        @endif
                                    </div>
                                    @if ($meta)
                                        <p class="text-[11px] text-ink-subtle mt-1">{{ implode(' · ', $meta) }}</p>
                                    @endif
                                </div>
                                <p class="col-start-2 sm:col-start-auto text-[12px] text-ink-muted leading-relaxed whitespace-pre-line">{{ $a['description'] }}</p>
                                <p class="col-start-2 sm:col-start-auto text-[12px] text-ink sm:text-right whitespace-nowrap">
                                    @if ($cd)
                                        <span class="sm:hidden text-ink-subtle">Cooldown </span>{{ $cd }}
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
        <div x-show="!any(null, null)" x-cloak
             class="p-6 text-center text-[13px] text-ink-muted">
            No abilities match.
        </div>
    </div>

    <p class="mt-4 text-[12px] text-ink-subtle leading-relaxed">
        From the game's own data{{ $build ? ', build '.$build : '' }}. Each ability shows its highest rank.
    </p>
</div>
</x-app-layout>
