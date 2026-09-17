@php
    use App\Enums\UserGuideStatus;

    $classColors = config('wow_classes.colors', []);
    $me = auth()->user();

    // First run: no guide of their own yet. Leads with the one thing the account is for, and
    // shows a real plan to copy. See App\Livewire\Home's docblock.
    $firstRun = $this->myGuides->isEmpty();
    $example = $firstRun ? $this->exampleGuide : null;
    $feed = $this->feed;
@endphp

<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    {{-- Arrivals from a redirect: Battle.net sign-in/sign-up, and the email-confirmation link. --}}
    @if (session('battlenet_status'))
        <div class="rounded-md border border-[#148EFF]/40 bg-[#148EFF]/5 px-4 py-2.5 mb-5 text-[13px] text-ink">{{ session('battlenet_status') }}</div>
    @endif
    @if (request()->boolean('verified'))
        <div class="rounded-md border border-green-500/40 bg-green-500/5 px-4 py-2.5 mb-5 text-[13px] text-ink">Email confirmed. Thanks.</div>
    @endif

    {{-- Header: a greeting and the one primary action. --}}
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
        <div class="min-w-0">
            <h1 class="font-display text-[26px] sm:text-3xl text-ink leading-tight">
                {{ $firstRun ? 'Welcome' : 'Welcome back' }}, {{ $me->name }}
            </h1>
            <p class="text-[13.5px] text-ink-muted mt-1">
                @if ($firstRun)
                    Turn a matchup into a plan you can actually play, instead of trying to hold it all in your head.
                @else
                    What players are planning, and what changed in the game.
                @endif
            </p>
        </div>
        @unless ($firstRun)
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" wire:click="createGuide('comp')" class="btn-primary">+ New comp guide</button>
                <button type="button" wire:click="createGuide('class')" class="btn-ghost">Class guide</button>
            </div>
        @endunless
    </div>

    @if ($firstRun)
        {{-- First run: one thing to do, explained once ----------------------------------- --}}
        <div class="linear-card border-line-gold relative overflow-hidden p-5 sm:p-7 mb-8">
            <x-ornament.corner position="tr" class="absolute top-3 right-3 w-10 h-10 text-gold/20"/>

            <h2 class="font-display text-[22px] sm:text-[26px] text-ink leading-tight">Build your first game plan</h2>
            <p class="text-[13.5px] text-ink-muted mt-2 max-w-2xl">
                Pick your comp, then drag in the abilities you'd actually press. MindCollector works
                out how long the control lasts after diminishing returns and how often you can run it
                again, from the game's own spell data.
            </p>

            {{-- What a plan is made of. Prompts, not section types: a guide has one sequence kind
                 (see UserGuideSectionKind) and the author's own titles say which is which. --}}
            <div class="grid sm:grid-cols-3 gap-3 mt-5">
                <div class="border-l-2 border-gold/60 pl-3">
                    <p class="text-[13px] font-semibold text-ink">The opener</p>
                    <p class="text-[12px] text-ink-muted mt-0.5">The CC chain that sets up your first kill attempt.</p>
                </div>
                <div class="border-l-2 border-gold/60 pl-3">
                    <p class="text-[13px] font-semibold text-ink">The go</p>
                    <p class="text-[12px] text-ink-muted mt-0.5">Cooldowns and control stacked into one window.</p>
                </div>
                <div class="border-l-2 border-gold/60 pl-3">
                    <p class="text-[13px] font-semibold text-ink">Their answers</p>
                    <p class="text-[12px] text-ink-muted mt-0.5">The defensives you need them to spend first.</p>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-2 mt-6">
                <button type="button" wire:click="createGuide('comp')" class="btn-primary justify-center">Plan a 3v3 or 2v2</button>
                <button type="button" wire:click="createGuide('class')" class="btn-secondary justify-center">Write a class guide</button>
                <span class="text-[12px] text-ink-subtle sm:ml-2">Private until you publish it.</span>
            </div>

            @if ($example && ($exampleUrl = $example->publicUrl()))
                <a href="{{ $exampleUrl }}" wire:navigate
                   class="inline-flex items-center gap-2 mt-5 text-[12.5px] text-ink-muted hover:text-gold transition-colors">
                    <span class="flex items-center gap-0.5">
                        @foreach ($example->members as $member)
                            @if ($member->specialization)
                                <x-spec-icon :spec="$member->specialization" size="w-5 h-5"/>
                            @endif
                        @endforeach
                    </span>
                    See one another player wrote: <span class="text-ink">&ldquo;{{ $example->title }}&rdquo;</span> &rarr;
                </a>
            @endif
        </div>
    @endif

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_280px]">

        {{-- The feed ------------------------------------------------------------------- --}}
        <section class="min-w-0">
            <div class="flex items-center justify-between gap-4 border-b border-line">
                <div class="flex items-center gap-5" role="tablist">
                    @foreach (['everyone' => 'Everyone', 'circle' => 'Friends & guilds'] as $scope => $label)
                        <button type="button" role="tab" wire:click="setFeedScope('{{ $scope }}')"
                                aria-selected="{{ $feedScope === $scope ? 'true' : 'false' }}"
                                class="-mb-px pb-2.5 text-[13px] font-medium border-b-2 transition-colors
                                       {{ $feedScope === $scope ? 'border-gold text-ink' : 'border-transparent text-ink-subtle hover:text-ink-muted' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <a href="{{ route('guides.browse') }}" wire:navigate class="pb-2.5 text-[12px] text-ink-subtle hover:text-gold">Browse all guides &rarr;</a>
            </div>

            <div wire:loading.class="opacity-60" wire:target="setFeedScope,loadMore">
                @forelse ($feed['items'] as $item)
                    @if ($item['type'] === 'guide')
                        <x-feed.guide-item :item="$item" :class-colors="$classColors" wire:key="feed-g-{{ $item['guide']->id }}"/>
                    @else
                        <x-feed.data-update :item="$item" wire:key="feed-d-{{ $item['update']->id }}"/>
                    @endif
                @empty
                    <div class="py-10 text-center">
                        @if ($feedScope === 'circle')
                            <p class="text-[13.5px] text-ink-muted">Nothing from your friends or guilds yet.</p>
                            <p class="text-[12.5px] text-ink-subtle mt-1">
                                <a href="{{ route('friends.index') }}" wire:navigate class="text-gold hover:text-gold-light">Add the players you queue with</a>
                                or <a href="{{ route('guilds.index') }}" wire:navigate class="text-gold hover:text-gold-light">start a guild</a>
                                &mdash; their guides show up here.
                            </p>
                        @else
                            <p class="text-[13.5px] text-ink-muted">No guides published yet. Yours could be the first.</p>
                        @endif
                    </div>
                @endforelse

                @if ($feed['hasMore'] && $feedLimit < \App\Livewire\Home::FEED_MAX)
                    <div class="pt-4 text-center">
                        <button type="button" wire:click="loadMore" class="btn-ghost">Show more</button>
                    </div>
                @endif
            </div>
        </section>

        {{-- Your things: small, secondary ------------------------------------------------- --}}
        <aside class="space-y-7 min-w-0">

            <x-quizzes.leaderboard :rows="$this->quizLeaderboard"/>

            {{-- Your guides --}}
            @unless ($firstRun)
                <div>
                    <div class="flex items-baseline justify-between gap-3 mb-2">
                        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Your guides</h2>
                        <a href="{{ route('guides.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All &rarr;</a>
                    </div>
                    @foreach ($this->myGuides as $guide)
                        <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate wire:key="mine-{{ $guide->id }}"
                           class="flex items-center gap-2 py-1.5 group">
                            <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $guide->status === UserGuideStatus::Published ? 'bg-green-400' : 'bg-ink-subtle' }}"
                                  title="{{ $guide->status === UserGuideStatus::Published ? 'Published' : 'Draft' }}"></span>
                            <span class="flex-1 min-w-0 text-[13px] text-ink-muted group-hover:text-gold truncate">{{ $guide->title }}</span>
                            <span class="text-[11px] text-ink-subtle shrink-0">{{ $guide->updated_at->diffForHumans(null, true, true) }}</span>
                        </a>
                    @endforeach
                </div>
            @endunless

            {{-- You can help edit --}}
            @if ($this->collaborating->isNotEmpty())
                <div>
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">You can help edit</h2>
                    @foreach ($this->collaborating as $guide)
                        <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate wire:key="collab-{{ $guide->id }}"
                           class="flex items-center gap-2 py-1.5 group">
                            <span class="flex-1 min-w-0 text-[13px] text-ink-muted group-hover:text-violet truncate">{{ $guide->title }}</span>
                            <span class="text-[11px] text-ink-subtle shrink-0">&#64;{{ $guide->user?->handle() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Characters --}}
            <div>
                <div class="flex items-baseline justify-between gap-3 mb-2">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Your characters</h2>
                    @if ($this->hasBattlenet)
                        <a href="{{ route('characters.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All &rarr;</a>
                    @endif
                </div>
                @if ($this->hasBattlenet)
                    @forelse ($this->characters as $character)
                        @php $exp = $character->bestExp(); @endphp
                        <a href="{{ route('characters.show', $character->id) }}" wire:navigate wire:key="char-{{ $character->id }}"
                           class="flex items-center gap-2 py-1 group">
                            @if ($character->specialization)
                                <x-spec-icon :spec="$character->specialization" size="w-6 h-6"/>
                            @endif
                            <span class="flex-1 min-w-0 text-[13px] font-medium truncate" style="color: {{ $classColors[$character->gameClass?->slug] ?? '#8A8A9A' }}">{{ $character->name }}</span>
                            @if ($exp)
                                <span class="text-[12px] text-gold tabular-nums shrink-0">{{ $exp['rating'] }}</span>
                            @endif
                        </a>
                    @empty
                        <p class="text-[12.5px] text-ink-subtle">Linked &mdash; no characters found yet.</p>
                    @endforelse
                @elseif ($this->battlenetAvailable)
                    <p class="text-[12.5px] text-ink-muted mb-2">Bring in your characters, ratings and talents, and sign guides with the one you play.</p>
                    <a href="{{ route('battlenet.redirect') }}" class="text-[12.5px] text-gold hover:text-gold-light">Link Battle.net &rarr;</a>
                @else
                    <p class="text-[12.5px] text-ink-subtle">Battle.net linking isn't available right now.</p>
                @endif
            </div>

            {{-- Friend requests: only when there is something to answer. --}}
            @if ($this->friendRequests->isNotEmpty())
                <div>
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Friend requests</h2>
                    @foreach ($this->friendRequests as $request)
                        <div class="flex items-center gap-2 py-1" wire:key="req-{{ $request->id }}">
                            <p class="flex-1 min-w-0 text-[13px] text-ink truncate">
                                &#64;{{ $request->requester?->handle() }} <span class="text-ink-subtle">wants to be friends</span>
                            </p>
                            <button type="button" wire:click="acceptFriend({{ $request->id }})" class="btn-primary text-[12px] px-2.5 py-1 shrink-0">Accept</button>
                            <button type="button" wire:click="declineFriend({{ $request->id }})" class="text-ink-subtle hover:text-red-400 px-1 shrink-0" title="Decline">&times;</button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Guilds --}}
            @if ($this->guilds->isNotEmpty())
                <div>
                    <div class="flex items-baseline justify-between gap-3 mb-2">
                        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Guilds</h2>
                        <a href="{{ route('guilds.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All &rarr;</a>
                    </div>
                    @foreach ($this->guilds as $guild)
                        <a href="{{ route('guilds.show', $guild) }}" wire:navigate wire:key="guild-{{ $guild->id }}"
                           class="flex items-center justify-between gap-2 py-1 text-[13px] text-ink-muted hover:text-gold transition-colors">
                            <span class="truncate">{{ $guild->name }}</span>
                            <span class="text-[11px] text-ink-subtle tabular-nums shrink-0">{{ $guild->members_count }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            <p class="text-[12px] text-ink-subtle border-t border-line pt-4">
                Your handle is <span class="font-mono text-gold">&#64;{{ $me->handle() }}</span>.
                <a href="{{ route('friends.index') }}" wire:navigate class="hover:text-gold">Add a friend &rarr;</a>
            </p>
        </aside>
    </div>
</div>
