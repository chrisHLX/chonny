@php
    use App\Enums\UserGuideSectionKind;
    use App\Enums\UserGuideStatus;
    use App\Enums\UserGuideVisibility;
    use App\Models\UserGuideMember;

    $drBadge = config('spell_display.dr_badges', []);
    $classColors = config('wow_classes.colors', []);
    $isPublished = $guide->status === UserGuideStatus::Published;
@endphp

<div class="max-w-7xl mx-auto px-4 py-8" x-data="{ noteFor: null, paletteFor: null }">

    {{-- Header ------------------------------------------------------------------- --}}
    <div class="flex items-start justify-between gap-6 mb-6">
        <div class="flex-1 min-w-0">
            <a href="{{ route('guides.index') }}" wire:navigate
               class="text-[12px] text-ink-subtle hover:text-gold transition-colors">&larr; My Guides</a>

            @if ($bracket = $guide->bracket())
                <span class="badge-blue ml-2">{{ $bracket }}</span>
            @endif

            <input type="text"
                   wire:model.live.debounce.600ms="title"
                   maxlength="120"
                   placeholder="Name this guide"
                   class="form-input mt-2 w-full font-display text-2xl bg-transparent border-0 border-b border-line rounded-none px-0 focus:ring-0 focus:border-gold">

            <input type="text"
                   wire:model.live.debounce.600ms="summary"
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
                    <button type="button" wire:click="setVisibility('{{ $option->value }}')"
                            class="px-3 py-2 rounded border text-left transition-colors {{ $guide->visibility === $option ? 'border-line-gold bg-gold-subtle' : 'border-line hover:border-line-strong' }}">
                        <span class="block text-[13px] font-medium text-ink">{{ $option->label() }}</span>
                        <span class="block text-[11px] text-ink-subtle">{{ $option->description() }}</span>
                    </button>
                @endforeach
            </div>

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

    {{-- Comp roster -------------------------------------------------------------- --}}
    <div class="linear-card p-4 mb-6">
        <div class="flex items-baseline justify-between gap-4 mb-3">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">The comp</h2>
            <span class="text-[12px] text-ink-subtle">Up to {{ UserGuideMember::MAX_MEMBERS }} &mdash; two for 2v2, three for 3v3</span>
        </div>

        <div class="grid sm:grid-cols-3 gap-3">
            @for ($slot = 0; $slot < UserGuideMember::MAX_MEMBERS; $slot++)
                @php $member = $this->members->firstWhere('position', $slot); @endphp
                <div wire:key="slot-{{ $slot }}" class="border border-line rounded p-3 bg-surface-2">
                    @if ($member && $member->specialization)
                        @php $color = $classColors[$member->specialization->gameClass?->slug] ?? '#8A8A9A'; @endphp
                        <div class="flex items-center gap-2.5">
                            <x-spec-icon :spec="$member->specialization" size="w-9 h-9"/>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-medium truncate" style="color: {{ $color }}">{{ $member->specialization->name }}</p>
                                <p class="text-[11px] text-ink-subtle truncate">{{ $member->specialization->gameClass?->name }}</p>
                            </div>
                            <button type="button" wire:click="removeMember({{ $slot }})"
                                    class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors">Clear</button>
                        </div>
                    @else
                        <button type="button" wire:click="openMemberPicker({{ $slot }})"
                                class="w-full h-full min-h-[52px] flex items-center justify-center gap-2 text-[13px] text-ink-subtle hover:text-gold transition-colors">
                            <span class="text-[16px] leading-none">+</span> Add a spec
                        </button>
                    @endif
                </div>
            @endfor
        </div>
    </div>

    {{-- Spec / opponent picker modal ---------------------------------------------- --}}
    @if ($pickingSlot !== null || $pickingOpponentFor !== null)
        @php $isOpponent = $pickingOpponentFor !== null; @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             style="background: rgba(9,9,13,.82)"
             wire:click.self="{{ $isOpponent ? 'closeOpponentPicker' : 'closeMemberPicker' }}">
            <div class="linear-card max-w-3xl w-full mt-10 p-5">
                <div class="flex items-baseline justify-between mb-4">
                    <h3 class="text-[15px] font-semibold text-ink">
                        {{ $isOpponent ? 'Who are you up against?' : 'Choose a spec' }}
                    </h3>
                    <button type="button" wire:click="{{ $isOpponent ? 'closeOpponentPicker' : 'closeMemberPicker' }}" class="btn-ghost">Close</button>
                </div>

                <div class="grid sm:grid-cols-2 gap-4 max-h-[65vh] overflow-y-auto pr-1">
                    @foreach ($this->classes as $class)
                        @php $color = $classColors[$class->slug] ?? '#8A8A9A'; @endphp
                        <div>
                            <p class="text-[11px] uppercase tracking-wider font-semibold mb-1.5" style="color: {{ $color }}">{{ $class->name }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($class->specializations as $spec)
                                    <button type="button" wire:click="{{ $isOpponent ? 'setOpponent' : 'setMember' }}({{ $spec->id }})"
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
                            <span class="badge-gold">{{ $section->kind->label() }}</span>
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
                        <button type="button"
                                x-on:click="paletteFor = (paletteFor === {{ $section->id }} ? null : {{ $section->id }})"
                                class="btn-ghost w-full mt-3 text-[12px]">
                            <span x-show="paletteFor !== {{ $section->id }}">+ Add an ability</span>
                            <span x-show="paletteFor === {{ $section->id }}" x-cloak>Close</span>
                        </button>

                        <div x-show="paletteFor === {{ $section->id }}" x-cloak class="mt-3 border-t border-line pt-3">
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
                                                <div class="mb-2.5">
                                                    <span class="{{ $drBadge[$groupName] ?? 'badge-gray' }}">{{ $groupName }}</span>
                                                    <div class="grid sm:grid-cols-2 gap-1 mt-1.5">
                                                        @foreach ($entries as $entry)
                                                            <button type="button"
                                                                    wire:key="pe-{{ $section->id }}-{{ $entry['spell']->id }}"
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
        <div class="flex flex-wrap gap-2">
            @foreach ($sectionKinds as $kind)
                <button type="button" wire:click="addSection('{{ $kind->value }}')" class="btn-ghost text-[12.5px]">
                    + {{ $kind->label() }}
                </button>
            @endforeach
        </div>
    </div>

    <livewire:spell-detail-modal/>
</div>
