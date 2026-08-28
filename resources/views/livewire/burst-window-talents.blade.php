<div class="max-w-5xl mx-auto px-4 py-8 space-y-5">
    <div>
        <a href="{{ route('top-damage-rotations') }}" class="inline-flex items-center gap-1 text-[11px] text-ink-subtle hover:text-gold transition-colors mb-3">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Burst Windows
        </a>
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Talent Build</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">
            {{ $spec?->name }} {{ $class?->name }}
        </h1>
        <p class="text-[12px] text-ink-muted mt-1">
            The real talents selected in the archived match behind this spec's {{ $length }}s Peak Burst Example — shown in our own talent calculator, view only. Nothing here can be changed.
        </p>
    </div>

    @if (!$talentBuild)
        <div class="linear-card p-6">
            <p class="text-[12px] text-ink-subtle italic">No resolved talent build on file for this spec/length yet.</p>
        </div>
    @elseif (!$spec)
        <div class="linear-card p-6">
            <p class="text-[12px] text-ink-subtle italic">Unknown class or spec.</p>
        </div>
    @else
        <livewire:talent-selector
            :spec-id="$spec->id"
            layout="grid"
            :read-only="true"
            :preset-chosen-entries="$presetChosenEntries"
            :preset-pvp-talent-ids="$presetPvpTalentIds"
            :key="'burst-window-talents-'.$spec->id.'-'.$length"
        />
    @endif
</div>
