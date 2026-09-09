@php
    use App\Enums\UserGuideStatus;
    use App\Enums\UserGuideVisibility;
@endphp

<div class="max-w-5xl mx-auto px-4 py-10">

    <div class="flex items-start justify-between gap-6 mb-8">
        <div>
            <p class="text-[11px] uppercase tracking-[0.16em] text-gold font-medium mb-2">Your work</p>
            <h1 class="font-display text-3xl text-ink">My Guides</h1>
            <p class="text-[14px] text-ink-muted mt-2 max-w-prose">
                Guides you've written for your own comps. Drafts stay private until you publish them,
                and a published guide is private until you make it public.
            </p>
        </div>

        {{-- Two buttons, and they choose the one thing that genuinely cannot change later: whether
             this guide is about a team or about a single spec. (The earlier pair, "New go" and
             "New CC chain", chose a section kind — a distinction that turned out not to exist; see
             App\Enums\UserGuideSectionKind.) --}}
        <div class="flex flex-col gap-2 shrink-0">
            <button type="button" wire:click="create('comp')" class="btn-primary">New comp guide</button>
            <button type="button" wire:click="create('class')" class="btn-secondary">New class guide</button>
        </div>
    </div>

    @if ($this->guides->isEmpty())
        <div class="linear-card p-10 text-center">
            <x-mc-icon name="icon-lightning-circle" class="w-10 h-10 text-gold/40 mx-auto mb-4"/>
            <h2 class="text-[16px] font-semibold text-ink mb-2">Nothing here yet</h2>
            <p class="text-[14px] text-ink-muted max-w-lg mx-auto mb-6">
                Two kinds. A <span class="text-ink">comp guide</span> is a 2v2 or 3v3 team &mdash; the
                opener, the go, and a VS column for the defensives you're trying to force. A
                <span class="text-ink">class guide</span> is one spec: a rotation, a technique, or a
                specific matchup like Rogue vs Disc. Either way we work out how much control survives
                diminishing returns and how often you can run it again.
            </p>
            <div class="flex items-center justify-center gap-2">
                <button type="button" wire:click="create('comp')" class="btn-primary">Start a comp guide</button>
                <button type="button" wire:click="create('class')" class="btn-secondary">Start a class guide</button>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($this->guides as $guide)
                {{-- STACKS ON MOBILE. This was one flex row holding the comp icons, the title and
                     three shrink-0 buttons; below ~640px the buttons refused to give up any width
                     and the title was crushed into a few characters — reported as "the text gets
                     squeezed in", worst on a 3v3 where six icons compete with it. The actions get
                     their own row on a narrow screen and only sit inline once there is room. --}}
                <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4" wire:key="guide-{{ $guide->id }}">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 shrink-0">
                            @forelse ($guide->members as $member)
                                @if ($member->specialization)
                                    <x-spec-icon :spec="$member->specialization" size="w-8 h-8"/>
                                @endif
                            @empty
                                <span class="w-8 h-8 rounded-md border border-dashed border-line-strong"></span>
                            @endforelse

                            {{-- The enemy team, on the row where an author picks a guide out of a
                                 list. Without it, two matchup guides for the same comp are
                                 indistinguishable until you open them. --}}
                            @if ($guide->enemies->isNotEmpty())
                                <span class="text-[10px] text-ink-subtle px-0.5">vs</span>
                                @foreach ($guide->enemies as $e)
                                    @if ($e->specialization)
                                        <x-spec-icon :spec="$e->specialization" size="w-6 h-6"/>
                                    @endif
                                @endforeach
                            @endif
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate
                                   class="text-[15px] font-semibold text-ink hover:text-gold transition-colors truncate">
                                    {{ $guide->title }}
                                </a>

                                @if ($guide->status === UserGuideStatus::Published)
                                    <span class="badge-green">Published</span>
                                    <span class="{{ $guide->visibility === UserGuideVisibility::Public ? 'badge-blue' : 'badge-gray' }}">
                                        {{ $guide->visibility->label() }}
                                    </span>
                                @else
                                    <span class="badge-gray">Draft</span>
                                @endif
                            </div>

                            <p class="text-[12px] text-ink-subtle mt-1">
                                {{ $guide->sections->count() }} {{ Str::plural('section', $guide->sections->count()) }}
                                @if ($bracket = $guide->bracket())
                                    &middot; {{ $bracket }}
                                @endif
                                &middot; edited {{ $guide->updated_at->diffForHumans() }}
                                {{-- Only once it is public: a draft's counters are always zero,
                                     and printing two zeroes reads as failure rather than as
                                     "nobody could have seen this yet". --}}
                                @if ($guide->status === UserGuideStatus::Published)
                                    &middot; {{ $guide->view_count }} {{ Str::plural('view', $guide->view_count) }}
                                    &middot; {{ $guide->like_count }} {{ Str::plural('like', $guide->like_count) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        @if ($guide->status === UserGuideStatus::Published && $url = $guide->publicUrl())
                            <a href="{{ $url }}" wire:navigate class="btn-ghost shrink-0">View</a>
                        @endif

                        <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate class="btn-ghost shrink-0">Edit</a>

                        <button type="button"
                                wire:click="delete({{ $guide->id }})"
                                wire:confirm="Delete &quot;{{ $guide->title }}&quot;? This can't be undone."
                                class="btn-danger shrink-0">Delete</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->shared->isNotEmpty())
        <div class="mt-10">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-3">Shared with you</h2>
            <div class="flex flex-col gap-2">
                @foreach ($this->shared as $guide)
                    <div class="linear-card p-4 flex items-center gap-4" wire:key="shared-{{ $guide->id }}">
                        <div class="flex items-center gap-1.5 shrink-0">
                            @foreach ($guide->members as $member)
                                @if ($member->specialization)
                                    <x-spec-icon :spec="$member->specialization" size="w-8 h-8"/>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[15px] font-semibold text-ink truncate">{{ $guide->title }}</p>
                            <p class="text-[12px] text-ink-subtle mt-1">
                                by {{ $guide->user?->username ?? $guide->user?->name }}
                                &middot; updated {{ $guide->updated_at->diffForHumans() }}
                            </p>
                        </div>
                        @if ($url = $guide->publicUrl())
                            <a href="{{ $url }}" wire:navigate class="btn-ghost shrink-0">Read</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
