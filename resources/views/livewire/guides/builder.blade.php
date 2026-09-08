@php
    use App\Enums\UserGuideSectionKind;
    use App\Enums\UserGuideStatus;
    use App\Enums\UserGuideVisibility;
    use App\Models\UserGuideMember;

    $drBadge = config('spell_display.dr_badges', []);
    $classColors = config('wow_classes.colors', []);
    $isPublished = $guide->status === UserGuideStatus::Published;
@endphp

<div class="max-w-7xl mx-auto px-4 py-8" x-data="{ noteFor: null }">

    {{-- Header ------------------------------------------------------------------- --}}
    <div class="flex items-start justify-between gap-6 mb-6">
        <div class="flex-1 min-w-0">
            <a href="{{ route('guides.index') }}" wire:navigate
               class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; My Guides</a>

            @if ($bracket = $guide->bracket())
                <span class="badge-blue ml-2">{{ $bracket }}</span>
            @endif

            {{-- .blur, not .live.debounce: a debounce still fires a full component re-render
                 mid-sentence every 600ms, which is felt directly as the field stuttering while
                 you type. Nothing on the page derives from the title, so there is nothing to keep
                 live — it saves when you click away, and the "Saved" stamp still confirms it. --}}
            <input type="text"
                   wire:model.blur="title"
                   maxlength="120"
                   placeholder="Name this guide"
                   class="form-input mt-2 w-full font-display text-2xl bg-transparent border-0 border-b border-line rounded-none px-0 focus:ring-0 focus:border-gold">

            <input type="text"
                   wire:model.blur="summary"
                   maxlength="500"
                   placeholder="What is this guide for? (optional)"
                   class="form-input mt-2 w-full text-[14px] bg-transparent border-0 px-0 focus:ring-0 text-ink-muted">
        </div>

        <div class="flex flex-col items-end gap-2 shrink-0">
            @if ($isPublished)
                <span class="badge-green">Published</span>
                <button type="button" wire:click="unpublish" class="btn-ghost">Unpublish</button>
            @else
                <span class="badge-gray">Draft</span>
                <button type="button" wire:click="publish" class="btn-primary" @disabled(! $guide->hasRoster())>Publish</button>
                @unless ($guide->hasRoster())
                    <span class="text-[11px] text-ink-subtle">Add a spec first</span>
                @endunless
            @endif

            @if ($savedAt)
                <span class="text-[11px] text-ink-subtle" wire:key="saved-{{ $savedAt }}">Saved {{ $savedAt }}</span>
            @endif
        </div>
    </div>

    {{-- Sharing ------------------------------------------------------------------ --}}
    @if ($isPublished)
        <div class="linear-card p-4 mb-6">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">Who can read this</h2>

            <div class="flex flex-wrap gap-2 mb-3">
                @foreach (UserGuideVisibility::cases() as $option)
                    @php $noGuild = $option === UserGuideVisibility::Guild && $this->myGuilds->isEmpty(); @endphp
                    <button type="button" wire:click="setVisibility('{{ $option->value }}')"
                            @disabled($noGuild)
                            class="px-3 py-2 rounded border text-left transition-colors {{ $guide->visibility === $option ? 'border-line-gold bg-gold-subtle' : 'border-line hover:border-line-strong' }} {{ $noGuild ? 'opacity-50 cursor-not-allowed' : '' }}">
                        <span class="block text-[13px] font-medium text-ink">{{ $option->label() }}</span>
                        <span class="block text-[11px] text-ink-subtle">
                            {{ $noGuild ? 'Join or create a guild first.' : $option->description() }}
                        </span>
                    </button>
                @endforeach
            </div>

            {{-- Which guild. Only shown when it is actually a choice — with one guild the
                 visibility button already adopted it, and a picker with a single option is noise. --}}
            @if ($guide->visibility === UserGuideVisibility::Guild && $this->myGuilds->count() > 1)
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach ($this->myGuilds as $g)
                        <button type="button" wire:click="setGuild({{ $g->id }})"
                                class="px-2.5 py-1.5 rounded border text-[12px] transition-colors {{ $guide->guild_id === $g->id ? 'border-line-gold bg-gold-subtle text-gold' : 'border-line text-ink-muted hover:border-line-strong' }}">
                            {{ $g->name }}
                        </button>
                    @endforeach
                </div>
            @elseif ($guide->visibility === UserGuideVisibility::Guild && $guide->guild)
                <p class="text-[12px] text-ink-muted mb-3">
                    Shared with <a href="{{ route('guilds.show', $guide->guild) }}" wire:navigate
                                   class="text-gold hover:text-gold-light">{{ $guide->guild->name }}</a>.
                </p>
            @endif

            @if ($guide->visibility === UserGuideVisibility::Public)
                @if ($url = $guide->publicUrl())
                    <div class="flex items-center gap-2" x-data="{ copied: false }">
                        <input type="text" readonly value="{{ $url }}"
                               class="form-input flex-1 text-[12px] font-mono"
                               x-ref="shareUrl" x-on:focus="$event.target.select()">
                        <button type="button" class="btn-ghost shrink-0"
                                x-on:click="navigator.clipboard.writeText($refs.shareUrl.value); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-show="!copied">Copy link</span>
                            <span x-show="copied" x-cloak class="text-gold">Copied</span>
                        </button>
                    </div>
                @endif
            @else
                <div class="flex items-start gap-2">
                    <div class="flex-1">
                        <div class="flex gap-2">
                            <input type="email" wire:model="shareEmail" placeholder="Their account email"
                                   class="form-input flex-1 text-[13px]"
                                   wire:keydown.enter="shareWith">
                            <button type="button" wire:click="shareWith" class="btn-secondary shrink-0">Add</button>
                        </div>
                        @if ($shareError)
                            <p class="text-[11.5px] text-red-400 mt-1">{{ $shareError }}</p>
                        @else
                            <p class="text-[11.5px] text-ink-subtle mt-1">
                                They need a MindCollector account already &mdash; we don't send invitations to
                                addresses that haven't signed up.
                            </p>
                        @endif
                    </div>
                </div>

                @if ($this->viewers->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5 mt-3">
                        @foreach ($this->viewers as $viewer)
                            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded border border-line bg-surface-2 text-[12px] text-ink"
                                  wire:key="viewer-{{ $viewer->id }}">
                                {{ $viewer->name }}
                                <button type="button" wire:click="unshare({{ $viewer->id }})"
                                        class="text-ink-subtle hover:text-red-400 transition-colors">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif

    <x-guides.health :health="$this->health" :editable="true"/>

    {{-- Roster ---------------------------------------------------------------------
         Two layouts over the same slot card. A comp guide is a team, so it is a row of three
         equal slots. A class guide is one spec and (optionally) one opponent, so it reads across
         as "you vs them" — the shape the guide's own title has ("Rogue vs Disc"). --}}
    @if ($guide->isClassGuide())
        <div class="linear-card p-4 mb-6">
            <div class="flex items-baseline justify-between gap-4 mb-3">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">The matchup</h2>
                <span class="text-[12px] text-ink-subtle">One spec &mdash; add an opponent only if the guide is about a matchup</span>
            </div>

            <div class="grid sm:grid-cols-[1fr_auto_1fr] items-center gap-3">
                <x-guides.member-slot :member="$this->members->firstWhere('position', 0)" :position="0"
                                      :class-colors="$classColors" label="Your spec" wire:key="slot-0"/>

                <span class="text-[12px] text-ink-subtle text-center sm:px-2">vs</span>

                <div class="border border-line rounded p-3 bg-surface-2">
                    <p class="text-[10px] uppercase tracking-[0.13em] text-ink-subtle mb-2">Opponent &mdash; optional</p>
                    @if ($guide->opponentSpec)
                        @php $oc = $classColors[$guide->opponentSpec->gameClass?->slug] ?? '#8A8A9A'; @endphp
                        <div class="flex items-center gap-2.5">
                            <x-spec-icon :spec="$guide->opponentSpec" size="w-9 h-9"/>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-medium truncate" style="color: {{ $oc }}">{{ $guide->opponentSpec->name }}</p>
                                <p class="text-[11px] text-ink-subtle truncate">{{ $guide->opponentSpec->gameClass?->name }}</p>
                            </div>
                            <button type="button" wire:click="clearGuideOpponent"
                                    class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors">Clear</button>
                        </div>
                        <p class="mt-2 text-[11px] text-ink-subtle">
                            A &ldquo;defensives to force&rdquo; section will use this automatically.
                        </p>
                    @else
                        <button type="button" wire:click="openGuideOpponentPicker"
                                class="w-full h-full min-h-[52px] flex items-center justify-center gap-2 text-[13px] text-ink-subtle hover:text-gold transition-colors">
                            <span class="text-[16px] leading-none">+</span> Name an opponent
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="linear-card p-4 mb-6">
            <div class="flex items-baseline justify-between gap-4 mb-3">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">The comp</h2>
                <span class="text-[12px] text-ink-subtle">Up to {{ $guide->maxMembers() }} &mdash; two for 2v2, three for 3v3</span>
            </div>

            <div class="grid sm:grid-cols-3 gap-3">
                @for ($slot = 0; $slot < $guide->maxMembers(); $slot++)
                    <x-guides.member-slot :member="$this->members->firstWhere('position', $slot)" :position="$slot"
                                          :class-colors="$classColors" wire:key="slot-{{ $slot }}"/>
                @endfor
            </div>
        </div>

        {{-- The enemy team. Optional: a guide about your own opener is still a good guide, so
             this stays collapsed until asked for rather than presenting three empty slots as
             something you owe the page. --}}
        @if ($guide->maxEnemies() > 0)
            <div class="linear-card p-4 mb-6" x-data="{ open: {{ $this->enemies->isNotEmpty() ? 'true' : 'false' }} }">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Playing against</h2>
                    <button type="button" x-on:click="open = !open" class="text-[12px] text-ink-subtle hover:text-gold transition-colors">
                        <span x-show="!open">+ Name the enemy team</span>
                        <span x-show="open" x-cloak>Hide</span>
                    </button>
                </div>

                <p class="text-[12px] text-ink-subtle mt-1" x-show="open" x-cloak>
                    Makes this a matchup guide — their defensives fill the VS columns.
                </p>

                <div class="grid sm:grid-cols-3 gap-3 mt-3" x-show="open" x-cloak>
                    @for ($slot = 0; $slot < $guide->maxEnemies(); $slot++)
                        <x-guides.member-slot :member="$this->enemies->firstWhere('position', $slot)" :position="$slot"
                                              side="enemy" :class-colors="$classColors" wire:key="enemy-slot-{{ $slot }}"/>
                    @endfor
                </div>
            </div>
        @endif
    @endif

    {{-- Talent tree for one comp slot ---------------------------------------------
         The real talent calculator, not a second implementation of one — the same
         <livewire:talent-selector> the admin default-build editor and the read-only Burst Window
         view both mount. It writes straight to this slot's own build (see its $buildId docblock:
         the id is #[Locked], and openTalents() has already confirmed the guide belongs to this
         author), so there is no save step and nothing to keep in sync. --}}
    @if ($editingTalentsFor !== null)
        @php $talentMember = $this->editingTalentsMember; @endphp
        @if ($talentMember && $talentMember->specialization && $talentMember->talent_build_id)
            <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
                 style="background: rgba(9,9,13,0.86)" wire:key="talents-{{ $talentMember->id }}">
                <div class="linear-card w-full max-w-[1400px] my-8 p-5">
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <x-spec-icon :spec="$talentMember->specialization" size="w-8 h-8"/>
                            <div class="min-w-0">
                                <h2 class="font-display text-[18px] text-ink truncate">
                                    {{ $talentMember->specialization->name }}
                                    {{ $talentMember->specialization->gameClass?->name }} talents
                                </h2>
                                <p class="text-[11.5px] text-ink-muted">
                                    What this guide is written for. Cooldowns, charges and DR categories
                                    everywhere in the guide follow these picks.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button type="button" wire:click="resetTalents({{ $editingTalentsFor }}, '{{ $editingTalentsSide }}')"
                                    class="btn-ghost text-[12px]">Use the default build</button>
                            <button type="button" wire:click="closeTalents" class="btn-primary text-[12px]">Done</button>
                        </div>
                    </div>

                    <livewire:talent-selector
                        :spec-id="$talentMember->specialization->id"
                        :build-id="$talentMember->talent_build_id"
                        layout="grid"
                        :key="'guide-talents-'.$talentMember->id.'-'.$talentMember->talent_build_id"/>
                </div>
            </div>
        @endif
    @endif

    {{-- Spec / opponent picker modal ---------------------------------------------- --}}
    @if ($pickingSlot !== null || $pickingOpponentFor !== null || $pickingGuideOpponent)
        @php
            // Three things use the same grid: a comp slot, a comp guide's per-section VS opponent,
            // and a class guide's single guide-level opponent. Only the close/select handlers and
            // the heading differ, so they share the markup rather than three near-identical modals.
            $isOpponent = $pickingOpponentFor !== null || $pickingGuideOpponent;
            $closeAction = $pickingGuideOpponent
                ? 'closeGuideOpponentPicker'
                : ($pickingOpponentFor !== null ? 'closeOpponentPicker' : 'closeMemberPicker');
            $selectAction = $pickingGuideOpponent
                ? 'setGuideOpponent'
                : ($pickingOpponentFor !== null ? 'setOpponent' : 'setMember');
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             style="background: rgba(9,9,13,.82)"
             wire:click.self="{{ $closeAction }}">
            <div class="linear-card max-w-3xl w-full mt-10 p-5">
                <div class="flex items-baseline justify-between mb-4">
                    <h3 class="text-[15px] font-semibold text-ink">
                        @if ($isOpponent)
                            Who are you up against?
                        @elseif ($pickingSide === 'enemy')
                            Add to the enemy team
                        @else
                            Choose a spec
                        @endif
                    </h3>
                    <button type="button" wire:click="{{ $closeAction }}" class="btn-ghost">Close</button>
                </div>

                <div class="grid sm:grid-cols-2 gap-4 max-h-[65vh] overflow-y-auto pr-1">
                    @foreach ($this->classes as $class)
                        @php $color = $classColors[$class->slug] ?? '#8A8A9A'; @endphp
                        <div>
                            <p class="text-[11px] uppercase tracking-wider font-semibold mb-1.5" style="color: {{ $color }}">{{ $class->name }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($class->specializations as $spec)
                                    <button type="button" wire:click="{{ $selectAction }}({{ $spec->id }})"
                                            class="flex items-center gap-1.5 px-2 py-1.5 rounded border border-line hover:border-line-gold hover:bg-gold-subtle transition-colors">
                                        <x-spec-icon :spec="$spec" size="w-6 h-6"/>
                                        <span class="text-[12px] text-ink">{{ $spec->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Sections ------------------------------------------------------------------ --}}
    @forelse ($this->rows as $rowIndex => $rowSections)
        @php $isSplit = $rowSections->count() > 1; @endphp
        <div class="grid {{ $isSplit ? 'lg:grid-cols-2' : 'grid-cols-1' }} gap-4 mb-4" wire:key="row-{{ $rowIndex }}">
            @foreach ($rowSections as $section)
                @php
                    $data = $this->resolved[$section->id] ?? null;
                    $opponent = $section->opponentSpec;
                @endphp
                <div class="linear-card p-4" wire:key="section-{{ $section->id }}">

                    <div class="flex items-start gap-2 mb-3">
                        <div class="flex-1 min-w-0">
                            {{-- No badge on a sequence: with one sequence kind the label would say
                             "Sequence" on almost every section, which tells a reader nothing the
                             author's own title does not say better. --}}
                        @unless ($section->kind === UserGuideSectionKind::Sequence)
                            <span class="badge-gold">{{ $section->kind->label() }}</span>
                        @endunless
                            <input type="text"
                                   value="{{ $section->title }}"
                                   maxlength="120"
                                   class="form-input mt-1.5 w-full text-[15px] font-semibold bg-transparent border-0 border-b border-line rounded-none px-0 focus:ring-0 focus:border-gold"
                                   x-on:blur="$wire.renameSection({{ $section->id }}, $event.target.value)"
                                   x-on:keydown.enter.prevent="$event.target.blur()">

                            @if ($section->kind->usesOpponent())
                                <button type="button" wire:click="openOpponentPicker({{ $section->id }})"
                                        class="flex items-center gap-1.5 mt-2 text-[12px] text-ink-subtle hover:text-gold transition-colors">
                                    @if ($opponent)
                                        <x-spec-icon :spec="$opponent" size="w-5 h-5"/>
                                        <span style="color: {{ $classColors[$opponent->gameClass?->slug] ?? '#8A8A9A' }}">
                                            vs {{ $opponent->name }} {{ $opponent->gameClass?->name }}
                                        </span>
                                    @else
                                        <span>+ Choose the opponent</span>
                                    @endif
                                </button>
                            @endif
                        </div>

                        <div class="flex items-center gap-0.5 shrink-0">
                            <button type="button" wire:click="moveSection({{ $section->id }}, -1)"
                                    class="text-ink-subtle hover:text-gold transition-colors px-1" title="Move up">&uarr;</button>
                            <button type="button" wire:click="moveSection({{ $section->id }}, 1)"
                                    class="text-ink-subtle hover:text-gold transition-colors px-1" title="Move down">&darr;</button>
                            <button type="button" wire:click="deleteSection({{ $section->id }})"
                                    wire:confirm="Delete &quot;{{ $section->title }}&quot; and its steps?"
                                    class="text-ink-subtle hover:text-red-400 transition-colors px-1" title="Delete section">&times;</button>
                        </div>
                    </div>

                    @if ($section->kind === UserGuideSectionKind::Text)
                        <textarea rows="6" maxlength="20000"
                                  placeholder="Markdown supported — **bold**, lists, headings."
                                  class="form-textarea w-full text-[13.5px]"
                                  x-on:blur="$wire.setSectionBody({{ $section->id }}, $event.target.value)">{{ $section->body }}</textarea>
                        @if (filled($section->body))
                            <div class="prose-guide mt-3 text-[13.5px] text-ink-muted">{!! $section->bodyHtml() !!}</div>
                        @endif
                    @else
                        @if ($data)
                            <x-guides.metrics :metrics="$data['metrics']" :tracks-control="$section->kind->tracksControl()"/>
                        @endif

                        <x-guides.section-steps :steps="$data['steps'] ?? []" :section="$section" :editable="true"/>

                        {{-- Palette, opened per section so the page isn't three palettes deep --}}
                        <div class="flex items-center gap-2 mt-3">
                        <button type="button" wire:click="togglePalette({{ $section->id }})"
                                wire:loading.attr="disabled" wire:target="togglePalette({{ $section->id }})"
                                class="btn-ghost w-full text-[12px]">
                            <span wire:loading.remove wire:target="togglePalette({{ $section->id }})">
                                {{ $openPaletteFor === $section->id ? 'Close' : '+ Add an ability' }}
                            </span>
                            <span wire:loading wire:target="togglePalette({{ $section->id }})">Loading kit&hellip;</span>
                        </button>
                        </div>

                        {{-- `search` is scoped to this palette's own x-data so two open palettes
                             filter independently. Purely client-side: the palette is already
                             rendered, so filtering it must not cost a Livewire round trip. --}}
                        {{-- Only the OPEN section's palette is built. Rendering all of them and
                             hiding the rest with x-show cost ~270ms and ~50 queries per section on
                             every round trip, palette-related or not — see Builder::$openPaletteFor. --}}
                        @if ($openPaletteFor === $section->id)
                        <div class="mt-3 border-t border-line pt-3" x-data="{ search: '' }">
                            @php $palette = $this->paletteFor($section->id); @endphp

                            @if ($palette->isEmpty())
                                <p class="text-[12.5px] text-ink-subtle">
                                    @if ($section->kind->usesOpponent())
                                        Choose the opponent above to see their defensive cooldowns.
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
                                                            <button type="button"
                                                                    wire:key="pe-{{ $section->id }}-{{ $entry['spell']->id }}"
                                                                    data-search="{{ Str::lower($entry->displayName().' '.$groupName) }}"
                                                                    x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                                                    wire:click="addSpell({{ $section->id }}, {{ $entry['spell']->spell_id }}, {{ $pSpec->id }})"
                                                                    class="flex items-center gap-2 p-1.5 rounded border border-transparent hover:border-line-gold hover:bg-gold-subtle text-left transition-colors">
                                                                <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                                                <span class="flex-1 min-w-0">
                                                                    <span class="block text-[12.5px] text-ink truncate">{{ $entry->displayName() }}</span>
                                                                    @if ($entry['cooldown']['seconds'] ?? null)
                                                                        <span class="block text-[10.5px] text-ink-subtle tabular-nums">{{ (int) $entry['cooldown']['seconds'] }}s CD</span>
                                                                    @endif
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
                        @endif
                    @endif
                </div>
            @endforeach

            @if (! $isSplit)
                <div class="flex items-center justify-center">
                    <button type="button" wire:click="addParallelSection({{ $rowIndex }}, 'defensives')"
                            class="text-[12px] text-ink-subtle hover:text-gold transition-colors border border-dashed border-line-strong rounded px-3 py-2">
                        + Add a VS column here
                    </button>
                </div>
            @endif
        </div>
    @empty
        <div class="linear-card p-10 text-center mb-4">
            <p class="text-[14px] text-ink-muted max-w-lg mx-auto">
                A guide is made of sections. Add a chain, a full go, some notes &mdash; or a VS column
                showing the defensives you're trying to force out of a specific opponent.
            </p>
        </div>
    @endforelse

    {{-- Add a section -------------------------------------------------------------- --}}
    <div class="linear-card p-4">
        <p class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">Add a section</p>
        <div class="grid sm:grid-cols-3 gap-2">
            @foreach ($sectionKinds as $kind)
                <button type="button" wire:click="addSection('{{ $kind->value }}')"
                        class="text-left border border-line rounded p-3 hover:border-line-gold hover:bg-gold-subtle transition-colors">
                    <span class="block text-[13px] text-ink font-medium">+ {{ $kind->label() }}</span>
                    <span class="block text-[11px] text-ink-subtle mt-0.5">{{ $kind->hint() }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <livewire:spell-detail-modal/>
</div>
