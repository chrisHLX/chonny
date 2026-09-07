<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="font-display text-3xl text-ink">Spell Finder</h1>
        <p class="text-ink-muted text-sm mt-1">Query the materialized spell shape directly — class/spec availability, category, DR category, cooldown role, and every curated CC/immunity flag, all as real columns. No raw SQL — every filter below is a parameterized query. See CLAUDE.md's "spell shape" section for what each field means and where it comes from.</p>
    </div>

    <div class="linear-card p-5 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Class</label>
                <select wire:model.live="classId" class="form-select w-full mt-1">
                    <option value="">Any</option>
                    @foreach ($classesList as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Spec</label>
                <select wire:model.live="specId" class="form-select w-full mt-1" @if(!$classId) disabled @endif>
                    <option value="">Any</option>
                    @foreach ($specsList as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Category</label>
                <select wire:model.live="category" class="form-select w-full mt-1">
                    <option value="">Any</option>
                    @foreach ($categoriesList as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">DR Category</label>
                <select wire:model.live="drCategory" class="form-select w-full mt-1">
                    <option value="">Any</option>
                    @foreach ($drCategoriesList as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Chain Target</label>
                <select wire:model.live="chainTarget" class="form-select w-full mt-1">
                    <option value="">Any</option>
                    @foreach ($chainTargetsList as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Cooldown (seconds)</label>
                <div class="flex gap-2 mt-1">
                    <input type="number" wire:model.live.debounce.400ms="cooldownMin" placeholder="min" class="form-input w-full">
                    <input type="number" wire:model.live.debounce.400ms="cooldownMax" placeholder="max" class="form-input w-full">
                </div>
            </div>
            <div class="md:col-span-2 lg:col-span-2">
                <label class="text-xs text-ink-subtle uppercase tracking-wide">Name contains</label>
                <input type="text" wire:model.live.debounce.400ms="nameSearch" placeholder="e.g. Barkskin" class="form-input w-full mt-1">
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-line grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide mb-1 block">Cooldown role (arena-log verified)</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isOffensiveCooldown" class="form-checkbox"> Offensive Cooldown</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isDefensiveCooldown" class="form-checkbox"> Defensive Cooldown</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isPriority" class="form-checkbox"> Real observed cast (is_priority)</label>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide mb-1 block">Tags</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isMobility" class="form-checkbox"> Mobility</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isPeel" class="form-checkbox"> Peel</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="isInterrupt" class="form-checkbox"> Interrupt</label>
            </div>
            <div>
                <label class="text-xs text-ink-subtle uppercase tracking-wide mb-1 block">Immunity / bypass</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="bypassesActiveDefense" class="form-checkbox"> Cannot be dodged/parried/blocked</label>
                <label class="flex items-center gap-2 text-sm text-ink-muted mt-1"><input type="checkbox" wire:model.live="silenceImmuneBySchool" class="form-checkbox"> Silence-immune (Physical school)</label>
            </div>
            <div class="md:col-span-2 lg:col-span-3">
                <label class="text-xs text-ink-subtle uppercase tracking-wide mb-1 block">Usable while (CC types this spell can still be cast through)</label>
                <div class="flex flex-wrap gap-3">
                    @foreach ($ccTokensList as $token => $label)
                        <label class="flex items-center gap-1.5 text-sm text-ink-muted">
                            <input type="checkbox" wire:model.live="usableWhileCc" value="{{ $token }}" class="form-checkbox"> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-line flex items-center justify-between">
            <p class="text-xs text-ink-subtle">{{ $results['total'] }} match{{ $results['total'] === 1 ? '' : 'es' }}@if($results['truncated']) — showing first {{ count($results['spells']) }}@endif</p>
            <button type="button" wire:click="resetFilters" class="btn-ghost text-xs">Clear all filters</button>
        </div>
    </div>

    <div class="linear-card p-5">
        @if ($results['spells']->isEmpty())
            <p class="text-ink-muted text-sm">No spells match these filters.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-subtle border-b border-line">
                            <th class="py-1.5 pr-4"></th>
                            <th class="py-1.5 pr-4">Spell</th>
                            <th class="py-1.5 pr-4">Category</th>
                            <th class="py-1.5 pr-4">DR Category</th>
                            <th class="py-1.5 pr-4">Cooldown</th>
                            <th class="py-1.5 pr-4">Available to</th>
                            <th class="py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results['spells'] as $spell)
                            @php
                                $availability = $spell->classAvailability
                                    ->map(fn ($a) => ($a->gameClass?->name ?? '?').' ('.($a->specialization?->name ?? 'all specs').')')
                                    ->unique()
                                    ->implode(', ');
                            @endphp
                            <tr class="border-b border-line/40">
                                <td class="py-1.5 pr-2 w-8"><x-spell-icon :spell="$spell" size="w-6 h-6"/></td>
                                <td class="py-1.5 pr-4">
                                    <button type="button"
                                            wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }} })"
                                            class="text-ink hover:text-gold underline decoration-dotted underline-offset-2 transition-colors">
                                        {{ $spell->display_name }}
                                    </button>
                                </td>
                                <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->category ?? '—' }}</td>
                                <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->dr_category ?? '—' }}</td>
                                <td class="py-1.5 pr-4 text-ink-muted">{{ $spell->cooldown_seconds !== null ? $spell->cooldown_seconds.'s' : '—' }}</td>
                                <td class="py-1.5 pr-4 text-ink-subtle text-xs max-w-sm truncate" title="{{ $availability }}">{{ $availability ?: '—' }}</td>
                                {{-- Direct link to the spell's permanent page. The name above opens
                                     the quick modal; this is the shareable/bookmarkable route to
                                     the same SpellProfile. --}}
                                <td class="py-1.5 w-8 text-right">
                                    <a href="{{ route('spell.show', $spell->id) }}"
                                       title="Open {{ $spell->display_name }} page"
                                       class="text-ink-subtle hover:text-gold transition-colors inline-block">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <livewire:spell-detail-modal/>
</div>
