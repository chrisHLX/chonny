@php
    $drBadge = config('spell_display.dr_badges', []);
    $classColors = config('wow_classes.colors', []);
@endphp

                        <div class="mt-3 border-t border-line pt-3" x-data="{ search: '', pending: {} }">

                            @if ($palette->isEmpty())
                                <p class="text-[12.5px] text-ink-subtle">
                                    @if ($section->kind->usesOpponent())
                                        {{ $guide->isClassGuide()
                                            ? 'Name the opponent above to see their abilities.'
                                            : 'Name the enemy team at the top of the page, or choose an opponent above, to see their abilities.' }}
                                    @else
                                        Add a spec to the comp above and its abilities appear here.
                                    @endif
                                </p>
                            @else
                                <input type="text" x-model="search"
                                       placeholder="Search this kit&hellip;"
                                       class="form-input !text-[12px] !py-1.5 w-full mb-3">

                                <div class="flex flex-col gap-4 max-h-[420px] overflow-y-auto pr-1">
                                    @foreach ($palette as $group)
                                        @php
                                            $pSpec = $group['spec'];
                                            $pColor = $classColors[$pSpec->gameClass?->slug] ?? '#8A8A9A';
                                        @endphp
                                        <div wire:key="pal-{{ $section->id }}-{{ $pSpec->id }}">
                                            <div class="flex items-center gap-2 mb-1.5 pb-1 border-b border-line">
                                                <x-spec-icon :spec="$pSpec" size="w-5 h-5"/>
                                                <span class="text-[12px] font-semibold" style="color: {{ $pColor }}">
                                                    {{ $pSpec->name }} {{ $pSpec->gameClass?->name }}
                                                </span>
                                            </div>

                                            @foreach ($group['groups'] as $groupName => $entries)
                                                {{-- A group hides itself when nothing inside it
                                                     matches, so searching does not leave a page of
                                                     empty headings behind. --}}
                                                <div class="mb-2.5"
                                                     data-search-group="{{ Str::lower($groupName.' '.$entries->map(fn ($e) => $e->displayName())->implode(' ')) }}"
                                                     x-show="search === '' || $el.dataset.searchGroup.includes(search.toLowerCase())">
                                                    <span class="{{ $drBadge[$groupName] ?? 'badge-gray' }}">{{ $groupName }}</span>
                                                    <div class="grid sm:grid-cols-2 gap-1 mt-1.5">
                                                        @foreach ($entries as $entry)
                                                            @php $addKey = $entry['spell']->spell_id.'-'.$pSpec->id; @endphp
                                                            {{-- Calls the BUILDER, not this component: the step lands in the
                                                                 builder's list and this palette stays as it is. `pending` spins
                                                                 only the ability that was clicked, and clears when the builder's
                                                                 request finishes (whether it worked or not). --}}
                                                            <button type="button"
                                                                    wire:key="pe-{{ $section->id }}-{{ $entry['spell']->id }}"
                                                                    data-search="{{ Str::lower($entry->displayName().' '.$groupName) }}"
                                                                    x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                                                    x-on:click="pending['{{ $addKey }}'] = true; $wire.$parent.addSpell({{ $section->id }}, {{ $entry['spell']->spell_id }}, {{ $pSpec->id }}).finally(() => delete pending['{{ $addKey }}'])"
                                                                    x-bind:disabled="pending['{{ $addKey }}'] === true"
                                                                    class="group flex items-center gap-2 p-1.5 rounded border border-transparent hover:border-line-gold hover:bg-gold-subtle text-left transition-colors disabled:opacity-50 disabled:cursor-wait">
                                                                <span class="relative shrink-0">
                                                                    <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                                                    <span x-show="pending['{{ $addKey }}']" x-cloak
                                                                          class="absolute inset-0 flex items-center justify-center rounded bg-surface-0/70">
                                                                        <svg class="w-3.5 h-3.5 animate-spin text-gold" viewBox="0 0 24 24" fill="none">
                                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                                            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/>
                                                                        </svg>
                                                                    </span>
                                                                </span>
                                                                <span class="flex-1 min-w-0">
                                                                    <span class="block text-[12.5px] text-ink truncate">{{ $entry->displayName() }}</span>
                                                                    <span class="flex items-center gap-1.5">
                                                                        @if ($entry['cooldown']['seconds'] ?? null)
                                                                            <span class="text-[10.5px] text-ink-subtle tabular-nums">{{ (int) $entry['cooldown']['seconds'] }}s CD</span>
                                                                        @endif
                                                                        {{-- Shown before the pick, not after: knowing Sap needs the
                                                                             target out of combat matters while you are choosing. --}}
                                                                        @if ($entry['spell']->requires_target_out_of_combat)
                                                                            <span class="text-[10px] text-violet" title="Requires stealth, and the target must be out of combat — realistically an opener.">stealth + OOC</span>
                                                                        @elseif ($entry['spell']->requires_stealth)
                                                                            <span class="text-[10px] text-violet" title="Only applies its crowd control while you are stealthed.">from stealth</span>
                                                                        @elseif ($entry->drCategory() === null && isset($drBadge[$groupName]))
                                                                            {{-- The plain twin of the stealth version above it (Rake's
                                                                                 bleed without the stun) — UserGuideChainService places
                                                                                 it here so both versions sit side by side. --}}
                                                                            <span class="text-[10px] text-ink-muted" title="The same ability used out of stealth: no {{ Str::lower($groupName) }}, so it adds no crowd control to the chain.">out of stealth &middot; no {{ Str::lower($groupName) }}</span>
                                                                        @endif
                                                                    </span>
                                                                </span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
