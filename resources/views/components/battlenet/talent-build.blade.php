@props(['view', 'key'])

{{-- A character's talent build in the site's real talent calculator, read-only.
     $view is CharacterTalentResolver::resolve() plus the snapshot's hero tree name — see the
     Characters/CharacterShow/Guides\Show components for how it is built. Talents the current patch's
     data does not have yet are named rather than silently left out of the tree. --}}
@if (! $view || ! $view['spec'])
    <p class="text-[13px] text-ink-subtle">No talent build on file for this spec.</p>
@else
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-3 text-[12px] text-ink-muted">
        @if (! empty($view['heroTree']))
            <span>Hero talents: <span class="text-ink">{{ $view['heroTree'] }}</span></span>
        @endif
        <span class="tabular-nums">{{ $view['resolvedCount'] }} of {{ $view['totalCount'] }} talents shown</span>
    </div>

    @if ($view['unresolved'] !== [] || $view['pvpUnresolved'] !== [])
        <div class="border border-line-gold bg-gold-subtle rounded px-3 py-2 mb-3 text-[12px] text-ink-muted">
            Not in our talent data yet (a newer patch than this site has imported):
            <span class="text-ink">{{ implode(', ', array_merge($view['unresolved'], $view['pvpUnresolved'])) }}</span>
        </div>
    @endif

    <div class="overflow-x-auto">
        <livewire:talent-selector
            :spec-id="$view['spec']->id"
            layout="grid"
            :read-only="true"
            :preset-chosen-entries="$view['chosenEntries']"
            :preset-pvp-talent-ids="$view['pvpTalentIds']"
            :key="$key"
        />
    </div>
@endif
