<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold text-ink">Spell Explorer</h1>
        <p class="text-[13px] text-ink-muted mt-1">
            Pick a class and spec to see its spells — cooldowns and charges reflect that spec's
            default talent build.
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
        <x-spells.table
            :entries="$this->spellReferences"
            :title="$specializations->firstWhere('id', $specId)?->name.' '.$classes->firstWhere('id', $classId)?->name.' Spells'"
            description="Cooldowns and charges reflect this spec's admin-curated default talent build (see /admin/talent-builds) — not a personal build."
        />

        @if (empty($this->spellReferences))
            <div class="linear-card p-5 text-[13px] text-ink-muted">
                No default talent build has been set for this spec yet — configure one at
                <a href="{{ route('admin.talent-builds') }}" class="text-gold hover:underline">/admin/talent-builds</a>
                to see spells here.
            </div>
        @endif
    @endif
</div>
