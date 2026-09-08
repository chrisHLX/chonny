{{-- 'position', never 'slot': $slot is RESERVED in a Blade component (Laravel always injects
     the component's slot content under that name, which wins over a same-named prop). A :slot="0"
     prop therefore renders as an empty string, and wire:click="openMemberPicker()" reaches Livewire
     with no argument -- a BindingResolutionException on a required int, at runtime only. --}}
@props(['member', 'position', 'side' => 'team', 'classColors' => [], 'label' => null])

{{-- One comp slot: the spec, and which talents it is playing. Shared by both guide layouts —
     a comp guide renders three of these, a class guide one — so the two cannot drift apart. --}}
<div class="border border-line rounded p-3 bg-surface-2">
    @if ($label)
        <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-2">{{ $label }}</p>
    @endif

    @if ($member && $member->specialization)
        @php $color = $classColors[$member->specialization->gameClass?->slug] ?? '#8A8A9A'; @endphp
        <div class="flex items-center gap-2.5">
            <x-spec-icon :spec="$member->specialization" size="w-9 h-9"/>
            <div class="flex-1 min-w-0">
                <p class="text-[13px] font-medium truncate" style="color: {{ $color }}">{{ $member->specialization->name }}</p>
                <p class="text-[11px] text-ink-subtle truncate">{{ $member->specialization->gameClass?->name }}</p>
            </div>
            <button type="button" wire:click="removeMember({{ $position }}, '{{ $side }}')"
                    class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors">Clear</button>
        </div>

        {{-- Which talents this slot is playing. Until the author opens this, the slot resolves
             through the spec's admin-curated default build, which is what every guide did before
             per-member builds existed. --}}
        <button type="button" wire:click="openTalents({{ $position }}, '{{ $side }}')"
                class="mt-2 w-full text-left text-[11px] transition-colors {{ $member->talent_build_id ? 'text-gold hover:text-gold-light' : 'text-ink-subtle hover:text-gold' }}">
            @if ($member->talent_build_id)
                &#9679; Custom talents &mdash; edit
            @else
                &#9675; Using the default build &mdash; choose talents
            @endif
        </button>
    @else
        <button type="button" wire:click="openMemberPicker({{ $position }}, '{{ $side }}')"
                class="w-full h-full min-h-[52px] flex items-center justify-center gap-2 text-[13px] text-ink-subtle hover:text-gold transition-colors">
            <span class="text-[16px] leading-none">+</span> Add a spec
        </button>
    @endif
</div>
