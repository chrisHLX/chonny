@props([
    'steps',
    'section',
    'editable' => false,
    'candidates' => [],
])

@php
    /**
     * One ability and the talents that change it — shared by the builder and the public read view,
     * for the same reason section-steps.blade.php is: the service promises a reader sees what the
     * author saw, and two copies of this markup would eventually make that untrue.
     *
     * $steps is UserGuideChainService's own resolved list, partitioned by payload.role rather than
     * resolved separately. Nothing about the subject or its modifiers is stored beyond an external
     * spell id, so the numbers below are whatever is true this patch.
     */
    $subject = collect($steps)->first(fn ($s) => ($s['block']->payload['role'] ?? null) === 'subject');
    $modifiers = collect($steps)->filter(fn ($s) => ($s['block']->payload['role'] ?? null) === 'modifier')->values();

    // What our data says each attached talent does, keyed by external spell id. The author's own
    // note lives on the block; this is the half the data supplies.
    $byId = collect($candidates)->keyBy('external_spell_id');
@endphp

@if (! $subject)
    <p class="text-[13px] text-ink-subtle">
        {{ $editable
            ? 'Add the ability this section is about — the talents that change it come next.'
            : 'This section has no ability yet.' }}
    </p>
@else
    @php $entry = $subject['entry']; @endphp

    <div class="rounded border border-line-gold bg-gold-subtle/40 p-3">
        <div class="flex items-start gap-3">
            @if ($entry)
                <x-spell-icon :spell="$entry['spell']" size="w-10 h-10" class="mt-0.5"/>
            @endif
            <div class="min-w-0 flex-1">
                <p class="text-[15px] font-medium text-ink">
                    {{ $entry ? $entry->displayName() : 'Ability no longer found' }}
                    @if ($entry && ($entry['cooldown']['seconds'] ?? null))
                        <span class="text-[11.5px] text-ink-subtle tabular-nums ml-1.5">{{ (int) $entry['cooldown']['seconds'] }}s CD</span>
                    @endif
                </p>
                @if ($entry && filled($entry['description']['text'] ?? null))
                    <p class="text-[12.5px] text-ink-muted mt-1 leading-snug">{{ $entry['description']['text'] }}</p>
                @endif
            </div>
            @if ($editable)
                <button type="button" wire:click="removeBlock({{ $subject['block']->id }})"
                        wire:confirm="Remove this ability and the talents attached to it?"
                        class="text-[11px] text-ink-subtle hover:text-red-400 shrink-0">Change</button>
            @endif
        </div>
    </div>

    {{-- The attached talents. Each carries what the data says it does, then what the author says
         it is for — the second is the half no field holds. --}}
    @if ($modifiers->isNotEmpty())
        <ul class="mt-3 space-y-2">
            @foreach ($modifiers as $mod)
                @php
                    $modEntry = $mod['entry'];
                    $externalId = (int) ($mod['block']->payload['external_spell_id'] ?? 0);
                    $facts = $byId->get($externalId);
                    $note = $mod['block']->payload['note'] ?? null;
                @endphp
                <li wire:key="synergy-mod-{{ $mod['block']->id }}"
                    class="flex items-start gap-3 p-2.5 rounded border border-line bg-surface-2/60">
                    @if ($modEntry)
                        <x-spell-icon :spell="$modEntry['spell']" size="w-8 h-8" class="mt-0.5"/>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] text-ink">
                            {{ $modEntry ? $modEntry->displayName() : 'Talent no longer found' }}
                            @if ($facts)
                                <span class="text-[11.5px] text-ink-subtle ml-1.5">
                                    {{ $facts['effect'] }}@if ($facts['magnitude']) <span class="text-gold tabular-nums">{{ $facts['magnitude'] }}</span>@endif
                                </span>
                            @endif
                        </p>

                        @if ($editable)
                            <textarea rows="2" maxlength="280"
                                      placeholder="Why this matters — when you use it, what it sets up."
                                      class="form-textarea w-full text-[12.5px] mt-1.5"
                                      x-on:change="$wire.setNote({{ $mod['block']->id }}, $event.target.value)">{{ $note }}</textarea>
                        @elseif (filled($note))
                            <p class="text-[12.5px] text-ink-muted mt-1 leading-snug">{{ $note }}</p>
                        @endif
                    </div>
                    @if ($editable)
                        <button type="button" wire:click="removeBlock({{ $mod['block']->id }})"
                                class="text-[11px] text-ink-subtle hover:text-red-400 shrink-0">Remove</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($editable)
        @php
            $attached = $modifiers->map(fn ($m) => (int) ($m['block']->payload['external_spell_id'] ?? 0))->all();
            $available = collect($candidates)->reject(fn ($c) => in_array($c['external_spell_id'], $attached, true));
        @endphp

        <div class="mt-3">
            @if ($available->isEmpty())
                <p class="text-[12px] text-ink-subtle">
                    {{ $candidates === []
                        ? 'Our data records nothing that modifies this ability. That is a gap worth reporting, not proof there is nothing.'
                        : 'Every talent we know of is already attached.' }}
                </p>
            @else
                <p class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1.5">
                    What changes it
                    <span class="normal-case tracking-normal font-normal text-ink-subtle">— from our own spell data, pick what the point is about</span>
                </p>
                <div class="flex flex-wrap gap-1.5 max-h-56 overflow-y-auto">
                    @foreach ($available as $candidate)
                        <button type="button"
                                wire:key="synergy-cand-{{ $section->id }}-{{ $candidate['external_spell_id'] }}-{{ $candidate['source'] }}"
                                wire:click="addSynergyModifier({{ $section->id }}, {{ $candidate['external_spell_id'] }})"
                                class="px-2 py-1 rounded border border-line hover:border-line-gold bg-surface-2 text-left">
                            <span class="block text-[12.5px] text-ink">{{ $candidate['name'] }}</span>
                            <span class="block text-[10.5px] text-ink-subtle">
                                {{ $candidate['effect'] }}@if ($candidate['magnitude']) <span class="text-gold tabular-nums">{{ $candidate['magnitude'] }}</span>@endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endif
