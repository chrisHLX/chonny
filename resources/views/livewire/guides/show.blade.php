@php
    use App\Enums\UserGuideSectionKind;
    use App\Enums\UserGuideVisibility;

    $classColors = config('wow_classes.colors', []);
@endphp

<div class="max-w-6xl mx-auto px-4 py-10">

    {{-- Provenance is the first thing on the page, not a footnote.
         Everything else on this site is derived from real match evidence; this is one player's
         own plan, and a reader has to be able to tell the difference at a glance. --}}
    <div class="flex items-start justify-between gap-6 mb-6 pb-6 border-b border-line">
        <div class="flex-1 min-w-0">
            <p class="text-[11px] uppercase tracking-[0.16em] text-violet font-medium mb-2">
                Player-written guide
            </p>

            <h1 class="font-display text-3xl text-ink" style="text-wrap: balance">{{ $guide->title }}</h1>

            <p class="text-[13px] text-ink-muted mt-2">
                by <span class="text-ink">{{ $guide->user?->username ?? $guide->user?->name }}</span>
                @if ($bracket = $guide->bracket())
                    &middot; {{ $bracket }}
                @elseif ($guide->isClassGuide())
                    {{-- A class guide has no bracket by design (see UserGuide::bracket()), so it
                         says what it actually is instead of leaving the reader to infer it. --}}
                    &middot; Class guide
                @endif
                &middot; updated {{ $guide->updated_at->diffForHumans() }}
            </p>

            @if ($guide->summary)
                <p class="text-[15px] text-ink-muted mt-3 max-w-prose">{{ $guide->summary }}</p>
            @endif
        </div>

        <div class="flex flex-col items-end gap-2 shrink-0">
            @if ($guide->isOwnedBy(auth()->user()))
                <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate class="btn-ghost">Edit</a>
                @if ($guide->visibility === UserGuideVisibility::Invited)
                    <span class="badge-gray">Private</span>
                @endif
            @endif
        </div>
    </div>

    {{-- The comp ------------------------------------------------------------------ --}}
    @if ($this->members->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 mb-8">
            @foreach ($this->members as $member)
                @if ($member->specialization)
                    @php $color = $classColors[$member->specialization->gameClass?->slug] ?? '#8A8A9A'; @endphp
                    <div class="flex items-center gap-2" wire:key="m-{{ $member->id }}">
                        <x-spec-icon :spec="$member->specialization" size="w-9 h-9"/>
                        <div>
                            <p class="text-[13px] font-medium leading-tight" style="color: {{ $color }}">
                                {{ $member->specialization->name }}
                            </p>
                            <p class="text-[11px] text-ink-subtle leading-tight">{{ $member->specialization->gameClass?->name }}</p>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- A comp guide's opposition, on the same line, so the matchup reads left to right
                 the way people say it out loud: "RMD vs TSG". --}}
            @if ($this->enemies->isNotEmpty())
                <span class="text-[12px] text-ink-subtle px-1">vs</span>
                @foreach ($this->enemies as $enemy)
                    @if ($enemy->specialization)
                        @php $ec = $classColors[$enemy->specialization->gameClass?->slug] ?? '#8A8A9A'; @endphp
                        <div class="flex items-center gap-2" wire:key="e-{{ $enemy->id }}">
                            <x-spec-icon :spec="$enemy->specialization" size="w-9 h-9"/>
                            <div>
                                <p class="text-[13px] font-medium leading-tight" style="color: {{ $ec }}">
                                    {{ $enemy->specialization->name }}
                                </p>
                                <p class="text-[11px] text-ink-subtle leading-tight">{{ $enemy->specialization->gameClass?->name }}</p>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif

            {{-- A class guide reads "you vs them" — the same shape its title has. Rendered inside
                 the roster row rather than as a separate block so the matchup is one line. --}}
            @if ($guide->isClassGuide() && $guide->opponentSpec)
                @php $oc = $classColors[$guide->opponentSpec->gameClass?->slug] ?? '#8A8A9A'; @endphp
                <span class="text-[12px] text-ink-subtle px-1">vs</span>
                <div class="flex items-center gap-2">
                    <x-spec-icon :spec="$guide->opponentSpec" size="w-9 h-9"/>
                    <div>
                        <p class="text-[13px] font-medium leading-tight" style="color: {{ $oc }}">
                            {{ $guide->opponentSpec->name }}
                        </p>
                        <p class="text-[11px] text-ink-subtle leading-tight">{{ $guide->opponentSpec->gameClass?->name }}</p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <x-guides.health :health="$this->health"/>

    {{-- Sections ------------------------------------------------------------------ --}}
    @forelse ($this->rows as $rowIndex => $rowSections)
        @php $isSplit = $rowSections->count() > 1; @endphp
        <div class="grid {{ $isSplit ? 'lg:grid-cols-2' : 'grid-cols-1' }} gap-4 mb-4" wire:key="row-{{ $rowIndex }}">
            @foreach ($rowSections as $section)
                @php
                    $data = $this->resolved[$section->id] ?? null;
                    $opponent = $section->opponentSpec;
                @endphp
                <div class="linear-card p-5" wire:key="section-{{ $section->id }}">
                    <div class="mb-3">
                        {{-- No badge on a sequence: with one sequence kind the label would say
                             "Sequence" on almost every section, which tells a reader nothing the
                             author's own title does not say better. --}}
                        @unless ($section->kind === UserGuideSectionKind::Sequence)
                            <span class="badge-gold">{{ $section->kind->label() }}</span>
                        @endunless
                        <h2 class="text-[17px] font-semibold text-ink mt-1.5">{{ $section->title }}</h2>

                        @if ($section->kind->usesOpponent() && $opponent)
                            <div class="flex items-center gap-1.5 mt-1.5">
                                <x-spec-icon :spec="$opponent" size="w-5 h-5"/>
                                <span class="text-[12px]" style="color: {{ $classColors[$opponent->gameClass?->slug] ?? '#8A8A9A' }}">
                                    vs {{ $opponent->name }} {{ $opponent->gameClass?->name }}
                                </span>
                            </div>
                        @endif
                    </div>

                    @if ($section->kind === UserGuideSectionKind::Text)
                        <div class="prose-guide text-[14px] text-ink-muted leading-relaxed">
                            {!! $section->bodyHtml() !!}
                        </div>
                    @else
                        @if ($data)
                            <x-guides.metrics :metrics="$data['metrics']" :tracks-control="$section->kind->tracksControl()"/>
                        @endif
                        <x-guides.section-steps :steps="$data['steps'] ?? []" :section="$section"/>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <p class="text-[14px] text-ink-muted">This guide doesn't have any sections yet.</p>
    @endforelse

    <p class="text-[11.5px] text-ink-subtle mt-8 pt-6 border-t border-line leading-relaxed max-w-prose">
        Written by a player, not derived from match data. Cooldowns, diminishing returns and durations
        are computed from MindCollector's own game data; the plan itself, and the order of it, is this
        author's.
    </p>

    {{-- Rating and comments ------------------------------------------------------
         Below the guide, never above it: the content is what someone came for, and a rating
         widget at the top asks for a judgement before they have read anything. --}}
    <div class="mt-8 pt-6 border-t border-line grid lg:grid-cols-[280px_1fr] gap-8">
        <div>
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Rating</h2>

            <div class="flex items-baseline gap-2">
                @if ($guide->rating_avg !== null)
                    <span class="font-display text-3xl text-gold tabular-nums">{{ number_format((float) $guide->rating_avg, 1) }}</span>
                    <span class="text-[11.5px] text-ink-subtle tabular-nums">
                        {{ $guide->rating_count }} {{ Str::plural('rating', $guide->rating_count) }}
                    </span>
                @else
                    <span class="text-[13px] text-ink-subtle">Not rated yet</span>
                @endif
            </div>

            @auth
                @if (! $guide->isOwnedBy(auth()->user()))
                    <div class="flex items-center gap-1 mt-3">
                        @for ($v = 1; $v <= 5; $v++)
                            <button type="button" wire:click="rate({{ $v }})"
                                    title="{{ $v }} out of 5"
                                    class="w-8 h-8 rounded border transition-colors tabular-nums text-[13px]
                                           {{ $myRating >= $v
                                              ? 'border-line-gold bg-gold-subtle text-gold'
                                              : 'border-line text-ink-subtle hover:border-line-gold hover:text-gold' }}">
                                {{ $v }}
                            </button>
                        @endfor
                    </div>
                    <p class="text-[11px] text-ink-subtle mt-1.5">
                        {{ $myRating ? 'Your rating — click another to change it.' : 'Rate this guide.' }}
                    </p>
                @else
                    <p class="text-[11.5px] text-ink-subtle mt-3">You cannot rate your own guide.</p>
                @endif
            @else
                <p class="text-[11.5px] text-ink-subtle mt-3">
                    <a href="{{ route('login') }}" class="text-gold hover:text-gold-light">Sign in</a> to rate this guide.
                </p>
            @endauth

            @if ($feedbackError)
                <p class="text-[11.5px] text-red-400 mt-2">{{ $feedbackError }}</p>
            @endif
        </div>

        <div>
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">
                Comments
                <span class="text-ink-subtle font-normal tabular-nums">({{ $this->comments->count() }})</span>
            </h2>

            @auth
                <div class="mb-4">
                    <textarea wire:model="comment" rows="3" maxlength="1000"
                              placeholder="Does this still work? What would you change?"
                              class="form-textarea w-full text-[13.5px]"></textarea>
                    <div class="flex justify-end mt-2">
                        <button type="button" wire:click="postComment" class="btn-primary text-[12px]">Post</button>
                    </div>
                </div>
            @endauth

            @forelse ($this->comments as $c)
                <div class="border-b border-line py-3" wire:key="c-{{ $c->id }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-[12px] text-ink">
                            {{ $c->user?->username ?? 'unknown' }}
                            <span class="text-ink-subtle ml-1">{{ $c->created_at?->diffForHumans() }}</span>
                        </p>
                        @auth
                            @if ($c->user_id === auth()->id() || $guide->isOwnedBy(auth()->user()))
                                <button type="button" wire:click="deleteComment({{ $c->id }})"
                                        class="text-[11px] text-ink-subtle hover:text-red-400 transition-colors shrink-0">Delete</button>
                            @endif
                        @endauth
                    </div>
                    {{-- Plain text, deliberately: this is written by anyone, so the safest render
                         is the one with no markup surface at all. --}}
                    <p class="text-[13.5px] text-ink-muted mt-1 whitespace-pre-line">{{ $c->body }}</p>
                </div>
            @empty
                <p class="text-[13px] text-ink-subtle">No comments yet.</p>
            @endforelse
        </div>
    </div>

    <livewire:spell-detail-modal/>
</div>
