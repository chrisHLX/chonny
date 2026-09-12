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

            {{-- WHY THIS IS AN INDICATOR AND NOT A SAVE BUTTON. Every action here already writes
                 immediately — there is no unsaved state to flush — so a Save button would promise
                 a step that does not exist and imply work is at risk until you press it, which is
                 the opposite of true. What was actually missing is the confirmation: the old
                 stamp only ever appeared AFTER a write, so during the round trip the page looked
                 inert and you could not tell whether a click had registered. This says which of
                 the two is happening, always. --}}
            <span class="flex items-center gap-1.5 text-[11px]" wire:key="save-state">
                <span wire:loading class="flex items-center gap-1.5 text-gold">
                    <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/>
                    </svg>
                    Saving&hellip;
                </span>
                <span wire:loading.remove class="text-ink-subtle">
                    @if ($savedAt)
                        <span class="text-green-400">&check;</span> All changes saved &middot; {{ $savedAt }}
                    @else
                        Changes save automatically
                    @endif
                </span>
            </span>
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

    {{-- Written as -------------------------------------------------------------------
         Opt-in: sign the guide with one of your own characters, and readers see its exp,
         ratings, gear and talents. Nothing is shown until you pick one. --}}
    <div class="linear-card p-4 mb-6">
        <div class="flex items-baseline justify-between gap-4 mb-3">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Written as</h2>
            <span class="text-[12px] text-ink-subtle">Optional &mdash; readers see this character's exp, gear and talents</span>
        </div>

        @if (! $this->hasBattlenet)
            <p class="text-[13px] text-ink-muted">
                <a href="{{ route('battlenet.redirect') }}" class="text-gold hover:text-gold-light">Link Battle.net</a>
                to sign this guide with one of your characters.
            </p>
        @elseif ($this->myCharacters->isEmpty())
            <p class="text-[13px] text-ink-muted">
                No characters at level {{ config('services.battlenet.detail_min_level', 70) }}+ on your linked account.
                <a href="{{ route('characters.index') }}" wire:navigate class="text-gold hover:text-gold-light">Your characters</a>
            </p>
        @else
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="setAuthorCharacter(null)"
                        class="px-3 py-2 rounded border text-[12.5px] transition-colors {{ $guide->battlenet_character_id === null ? 'border-line-gold bg-gold-subtle text-ink' : 'border-line text-ink-muted hover:border-line-strong' }}">
                    Don't sign it
                </button>

                @foreach ($this->myCharacters as $character)
                    @php
                        $picked = $guide->battlenet_character_id === $character->id;
                        $exp = $character->bestExp();
                        $cc = $classColors[$character->gameClass?->slug] ?? '#8A8A9A';
                    @endphp
                    <button type="button" wire:click="setAuthorCharacter({{ $character->id }})" wire:key="author-{{ $character->id }}"
                            class="flex items-center gap-2 px-2.5 py-1.5 rounded border text-left transition-colors {{ $picked ? 'border-line-gold bg-gold-subtle' : 'border-line hover:border-line-strong' }}">
                        @if ($character->specialization)
                            <x-spec-icon :spec="$character->specialization" size="w-7 h-7"/>
                        @endif
                        <span>
                            <span class="block text-[12.5px] font-medium leading-tight" style="color: {{ $cc }}">{{ $character->name }}</span>
                            <span class="block text-[10.5px] text-ink-subtle leading-tight">
                                {{ $character->realm_name }}@if ($exp) &middot; <span class="text-gold tabular-nums">{{ $exp['rating'] }}</span> exp @endif
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

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
                                      :class-colors="$classColors" label="Your spec" wire:key="slot-0"
                                      :character-name="$guide->authorCharacter?->name" :character-specs="$this->authorCharacterSpecs"/>

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
                                          :class-colors="$classColors" wire:key="slot-{{ $slot }}"
                                          :character-name="$guide->authorCharacter?->name" :character-specs="$this->authorCharacterSpecs"/>
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

                {{-- Says outright that blank is a valid, finished state. Without this the empty
                     slots read as something you still owe the page, when a general opener guide
                     is a perfectly good guide. --}}
                <p class="text-[12px] text-ink-subtle mt-1" x-show="!open">
                    Optional — leave blank for a general guide that works against any team.
                </p>

                <p class="text-[12px] text-ink-subtle mt-1" x-show="open" x-cloak>
                    Naming a team makes this a matchup guide — their defensives fill the VS columns,
                    and people can find it by searching for that comp. Leave it blank for a general guide.
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
        @php
            $isSplit = $rowSections->count() > 1;
            // Keyed by the sections IN the row, not by the row NUMBER. The row number is the one
            // thing a reorder changes, so keying on it meant the row nodes stayed put while morph
            // swapped their contents — the cards get rebuilt in place instead of moving, which is
            // both wasteful and the shape of problem where an <input>/<textarea> keeps a stale
            // value because its `value` property has diverged from its HTML attribute.
            $rowKey = 'row-'.$rowSections->pluck('id')->implode('-');
        @endphp
        <div class="grid {{ $isSplit ? 'lg:grid-cols-2' : 'grid-cols-1' }} gap-4 mb-4" wire:key="{{ $rowKey }}">
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
                            {{-- x-on:change, NOT x-on:blur. `change` fires on blur only when the
                                 value actually changed, so clicking a button on this card no
                                 longer fires a save-and-re-render for an edit that never
                                 happened — which re-rendered the very buttons being clicked,
                                 between mousedown and mouseup. --}}
                            <input type="text"
                                   value="{{ $section->title }}"
                                   maxlength="120"
                                   class="form-input mt-1.5 w-full text-[15px] font-semibold bg-transparent border-0 border-b border-line rounded-none px-0 focus:ring-0 focus:border-gold"
                                   x-on:change="$wire.renameSection({{ $section->id }}, $event.target.value)"
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
                            {{-- The prompt names what is actually at stake: a Notes section has no
                                 steps, and telling someone their steps are about to go is both
                                 wrong and alarming. --}}
                            <button type="button" wire:click="deleteSection({{ $section->id }})"
                                    wire:confirm="Delete &quot;{{ $section->title }}&quot;{{ $section->kind->isSequence() ? ' and its steps' : '' }}?"
                                    class="text-ink-subtle hover:text-red-400 transition-colors px-1" title="Delete section">&times;</button>
                        </div>
                    </div>

                    @if ($section->kind === UserGuideSectionKind::Text)
                        <textarea rows="6" maxlength="20000"
                                  placeholder="Markdown supported — **bold**, lists, headings."
                                  class="form-textarea w-full text-[13.5px]"
                                  x-on:change="$wire.setSectionBody({{ $section->id }}, $event.target.value)">{{ $section->body }}</textarea>
                        @if (filled($section->body))
                            <div class="prose-guide mt-3 text-[13.5px] text-ink-muted">{!! $section->bodyHtml() !!}</div>
                        @endif
                    @else
                        @if ($data)
                            <x-guides.metrics :metrics="$data['metrics']" :tracks-control="$section->kind->tracksControl()"/>
                        @endif

                        <x-guides.section-steps :steps="$data['steps'] ?? []" :section="$section" :editable="true"/>

                        {{-- A placeholder row lands in the list the instant an ability is clicked,
                             so the plan visibly grows on the click rather than after the round trip.
                             DELIBERATELY A SKELETON, NOT THE REAL ROW: the cooldown, the DR
                             percentage and every following step's percentage are all computed
                             server-side, so rendering a "real" row here would show numbers that
                             change a moment later — and a step that silently re-rates itself is
                             worse than one that takes an extra beat to appear. wire:target is the
                             bare method name here (not the exact call as on the palette buttons)
                             because this row answers "is something being added", whichever ability
                             it was. --}}
                        <div wire:loading.flex wire:target="addSpell" wire:key="pending-{{ $section->id }}"
                             class="items-center gap-3 p-2.5 mt-2 rounded border border-dashed border-line-gold bg-surface-2/60">
                            <span class="w-4 shrink-0"></span>
                            <span class="w-8 h-8 rounded bg-surface-3 animate-pulse shrink-0"></span>
                            <span class="flex-1 min-w-0">
                                <span class="block h-3 w-32 rounded bg-surface-3 animate-pulse"></span>
                                <span class="block h-2.5 w-20 rounded bg-surface-3 animate-pulse mt-1.5"></span>
                            </span>
                            <span class="text-[11px] text-gold shrink-0">Adding&hellip;</span>
                        </div>

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
                                                            @php $addCall = 'addSpell('.$section->id.', '.$entry['spell']->spell_id.', '.$pSpec->id.')'; @endphp
                                                            {{-- wire:target names this EXACT call, params included, so only the
                                                                 ability you clicked reacts — targeting the bare method would spin
                                                                 every button in the kit at once. --}}
                                                            <button type="button"
                                                                    wire:key="pe-{{ $section->id }}-{{ $entry['spell']->id }}"
                                                                    data-search="{{ Str::lower($entry->displayName().' '.$groupName) }}"
                                                                    x-show="search === '' || $el.dataset.search.includes(search.toLowerCase())"
                                                                    wire:click="{{ $addCall }}"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="{{ $addCall }}"
                                                                    class="group flex items-center gap-2 p-1.5 rounded border border-transparent hover:border-line-gold hover:bg-gold-subtle text-left transition-colors disabled:opacity-50 disabled:cursor-wait">
                                                                <span class="relative shrink-0">
                                                                    <x-spell-icon :spell="$entry['spell']" size="w-6 h-6"/>
                                                                    <span wire:loading wire:target="{{ $addCall }}"
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
