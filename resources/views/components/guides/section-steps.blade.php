@props(['steps', 'section', 'editable' => false])

@php
    // One definition of how a step renders, shared by the builder and the public read view — the
    // service promises a reader sees exactly what the author saw, and two copies of this markup
    // would eventually make that untrue.
    $drBadge = config('spell_display.dr_badges', []);
    $classColors = config('wow_classes.colors', []);

    // Phases are dividers in the same flat list, so only real abilities take a step number —
    // otherwise "step 4" would count the headings between them and stop matching what a reader
    // is looking at.
    $stepNo = 0;

    // Enemy specs a phase may be aimed at, resolved once rather than per block.
    $enemyRows = $section->guide->relationLoaded('enemies')
        ? $section->guide->enemies
        : $section->guide->enemies()->with('specialization.gameClass')->get();
    $enemyById = $enemyRows->pluck('specialization')->filter()->keyBy('id');
@endphp

@if (empty($steps))
    <p class="text-[13px] text-ink-subtle py-6 text-center">
        {{ $editable ? 'Add an ability from the palette to start.' : 'Nothing here yet.' }}
    </p>
@else
    <ul
        @if ($editable)
            x-sortable=".chain-handle"
            x-on:sorted="$wire.reorder({{ $section->id }}, $event.detail)"
        @endif
        class="flex flex-col gap-2">

        @foreach ($steps as $i => $step)
            @php
                $block = $step['block'];
                $isPhase = $block->block_type === App\Enums\UserGuideBlockType::Phase;
            @endphp

            @if ($isPhase)
                @php
                    $pTarget = $block->phaseTarget();
                    $pSpec = $enemyById->get($block->phaseTargetSpecId());
                    $pColor = $classColors[$pSpec?->gameClass?->slug] ?? '#8A8A9A';
                @endphp

                {{-- A phase reads as a rule above the steps it governs, not as another step. --}}
                <li wire:key="block-{{ $block->id }}"
                    data-value="{{ $block->id }}"
                    class="flex items-center gap-2 mt-3 first:mt-0">
                    @if ($editable)
                        <span class="chain-handle cursor-grab active:cursor-grabbing text-ink-subtle select-none" title="Drag to reorder">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                            </svg>
                        </span>
                    @endif

                    @if ($editable)
                        <input type="text"
                               value="{{ $block->text() }}"
                               maxlength="60"
                               placeholder="Name this phase"
                               class="bg-transparent border-0 border-b border-transparent hover:border-line focus:border-gold focus:ring-0 px-0 py-0 text-[11px] uppercase tracking-[0.13em] text-ink font-semibold w-40"
                               x-on:change="$wire.setPhaseName({{ $block->id }}, $event.target.value)">
                    @else
                        <span class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">{{ $block->text() }}</span>
                    @endif

                    @if ($pSpec)
                        <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-surface-3"
                              style="color: {{ $pColor }}">
                            <x-spec-icon :spec="$pSpec" size="w-3.5 h-3.5"/>
                            on their {{ $pSpec->name }}
                        </span>
                    @elseif ($pTarget)
                        <span class="{{ $pTarget->badgeClass() }}">{{ $pTarget->phrase() }}</span>
                    @endif

                    @if ($editable)
                        <select class="bg-transparent border-0 text-[10.5px] text-ink-subtle focus:ring-0 py-0 pl-1 pr-5 cursor-pointer hover:text-gold"
                                x-on:change="$wire.setPhaseTarget({{ $block->id }}, $event.target.value)">
                            <option value="">No target</option>
                            @foreach (App\Enums\UserGuidePhaseTarget::cases() as $role)
                                <option value="{{ $role->value }}" @selected($pTarget === $role && ! $pSpec)>{{ $role->label() }}</option>
                            @endforeach
                            @foreach ($enemyById as $es)
                                <option value="spec:{{ $es->id }}" @selected($pSpec?->id === $es->id)>Their {{ $es->name }} {{ $es->gameClass?->name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <span class="flex-1 border-t border-line"></span>

                    @if ($editable)
                        <button type="button" wire:click="removeBlock({{ $block->id }})"
                                class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors px-1.5">Remove</button>
                    @endif
                </li>
                @continue
            @endif

            @php
                $stepNo++;
                $entry = $step['entry'];
                $dr = $step['dr'];
                $pct = $dr['dr_percentage'] ?? 100;
                $stepSpec = $step['spec'];
                $stepColor = $classColors[$stepSpec?->gameClass?->slug] ?? '#8A8A9A';
                $cat = $entry?->drCategory();
                $durLabel = $step['duration'] !== null
                    ? rtrim(rtrim(number_format($step['duration'], 1), '0'), '.').'s'.($pct < 100 ? ' · '.$pct.'%' : '')
                    : null;
            @endphp

            <li wire:key="block-{{ $block->id }}"
                data-value="{{ $block->id }}"
                class="flex items-start gap-3 p-2.5 rounded border border-line bg-surface-2 {{ $pct === 0 ? 'opacity-60' : '' }}">

                @if ($editable)
                    <span class="chain-handle cursor-grab active:cursor-grabbing text-ink-subtle pt-1.5 select-none" title="Drag to reorder">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                        </svg>
                    </span>
                @endif

                <span class="font-display text-[14px] text-gold tabular-nums pt-1 w-4 shrink-0">{{ $stepNo }}</span>

                @if ($step['unresolved'])
                    <div class="flex-1">
                        <p class="text-[13px] text-red-400 font-medium">Ability no longer found</p>
                        <p class="text-[11px] text-ink-subtle mt-0.5">
                            Spell {{ $block->externalSpellId() }} isn't in the current patch data.
                            {{ $editable ? 'Your step was kept so you can replace it.' : '' }}
                        </p>
                    </div>
                @else
                    <x-spell-icon :spell="$entry['spell']" size="w-8 h-8" class="mt-0.5"/>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button"
                                    wire:click="$dispatch('show-spell-detail', {
                                        spellId: {{ (int) $entry['spell']->id }},
                                        classId: {{ (int) ($stepSpec?->class_id ?? 0) }},
                                        specId: {{ (int) ($stepSpec?->id ?? 0) }}
                                    })"
                                    class="text-[13.5px] font-medium text-ink hover:text-gold transition-colors">
                                {{ $entry->displayName() }}
                            </button>

                            @if ($cat)
                                <span class="{{ $drBadge[$cat] ?? 'badge-gray' }}">{{ $cat }}</span>
                            @elseif ($entry['offensiveDefensive']['label'] ?? null)
                                <span class="badge-orange">{{ $entry['offensiveDefensive']['label'] }}</span>
                            @endif

                            @if ($cat && $pct === 0)
                                <span class="badge-gray">Immune &mdash; no effect</span>
                            @elseif ($cat && $durLabel)
                                <span class="badge-amber tabular-nums">{{ $durLabel }}</span>
                            @elseif ($cat && $pct < 100)
                                <span class="badge-amber">{{ $pct }}% duration</span>
                            @endif

                            @if ($entry['cooldown']['seconds'] ?? null)
                                <span class="text-[11px] text-ink-subtle tabular-nums">{{ (int) $entry['cooldown']['seconds'] }}s CD</span>
                            @endif
                        </div>

                        <p class="text-[11px] mt-0.5" style="color: {{ $stepColor }}">
                            {{ $stepSpec?->name }} {{ $stepSpec?->gameClass?->name }}
                        </p>

                        @if ($dr['dr_reason'] ?? null)
                            <p class="text-[11.5px] text-ink-subtle mt-0.5">{{ $dr['dr_reason'] }}</p>
                        @endif

                        @if ($cat && $step['duration'] === null)
                            <p class="text-[11.5px] text-ink-subtle mt-0.5">No verified PvP duration on file &mdash; not counted in the total.</p>
                        @endif

                        @if ($note = $block->note())
                            <p class="text-[12px] text-ink-muted mt-1 italic">{{ $note }}</p>
                        @endif

                        @if ($editable)
                            <div x-show="noteFor === {{ $block->id }}" x-cloak class="mt-2">
                                <input type="text"
                                       value="{{ $block->note() }}"
                                       maxlength="280"
                                       placeholder="When does this step apply?"
                                       class="form-input w-full text-[13px]"
                                       x-on:keydown.enter.prevent="$wire.setNote({{ $block->id }}, $event.target.value); noteFor = null"
                                       x-on:blur="$wire.setNote({{ $block->id }}, $event.target.value); noteFor = null">
                            </div>
                        @endif
                    </div>
                @endif

                @if ($editable)
                    <div class="flex items-center gap-1 shrink-0">
                        @unless ($step['unresolved'])
                            <button type="button"
                                    x-on:click="noteFor = (noteFor === {{ $block->id }} ? null : {{ $block->id }})"
                                    class="text-[11px] text-ink-subtle hover:text-gold transition-colors px-1.5 py-1">
                                Note
                            </button>
                        @endunless
                        <button type="button"
                                wire:click="removeBlock({{ $block->id }})"
                                class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors px-1.5 py-1">
                            Remove
                        </button>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@endif
