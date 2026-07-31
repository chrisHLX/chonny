<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold text-ink">Default Talent Builds</h1>
        <p class="text-[13px] text-ink-muted mt-1">
            Pick a class and spec, then choose the talents that should apply by default for anyone
            who hasn't saved their own build for that spec (e.g. a real top-rated player's loadout).
        </p>
    </div>

    <div class="linear-card p-4 flex flex-wrap gap-3">
        <select wire:model.live="classId" class="form-select">
            <option value="">Choose class…</option>
            @foreach ($classes as $class)
                <option value="{{ $class->id }}">{{ $class->name }}</option>
            @endforeach
        </select>

        @if ($classId)
            <select wire:model.live="specId" class="form-select">
                <option value="">Choose spec…</option>
                @foreach ($specializations as $spec)
                    <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($specId)
        <livewire:talent-selector :spec-id="$specId" :is-default-editor="true" :key="'default-build-'.$specId" />
    @endif
</div>
