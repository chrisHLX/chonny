@php
    use App\Enums\UserGuideStatus;

    $classColors = config('wow_classes.colors', []);
    $me = auth()->user();
    $games = $this->recentGames;
    $feed = $this->feed;
    // Game-data updates fold into one line above the guides (2026-11-03): five "Tooltips updated"
    // items used to lead the page.
    $items = collect($feed['items']);
    $dataUpdate = $items->firstWhere('type', 'data');
    $guideItems = $items->where('type', 'guide');
@endphp

<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    {{-- Arrivals from a redirect: Battle.net sign-in/sign-up, and the email-confirmation link. --}}
    @if (session('battlenet_status'))
        <div class="rounded-md border border-[#148EFF]/40 bg-[#148EFF]/5 px-4 py-2.5 mb-5 text-[13px] text-ink">{{ session('battlenet_status') }}</div>
    @endif
    @if (request()->boolean('verified'))
        <div class="rounded-md border border-green-500/40 bg-green-500/5 px-4 py-2.5 mb-5 text-[13px] text-ink">Email confirmed. Thanks.</div>
    @endif

    <h1 class="font-display text-[26px] sm:text-3xl text-ink leading-tight mb-5">
        {{ in_array($games['state'], ['none', 'waiting'], true) ? 'Welcome' : 'Welcome back' }}, {{ $me->name }}
    </h1>

    {{-- Your games first (2026-11-03): what the account is for. --}}
    @include('livewire.partials.home-games', ['g' => $games])

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_280px]">

        {{-- Other players' guides --------------------------------------------------------- --}}
        <section class="min-w-0">
            <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Player Guides</h2>
            @if ($dataUpdate)
                <x-feed.data-line :item="$dataUpdate"/>
            @endif
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
                @forelse ($guideItems as $item)
                    <x-feed.guide-item :item="$item" :class-colors="$classColors" wire:key="feed-g-{{ $item['guide']->id }}"/>
                @empty
                    <div class="py-10 text-center">
                        @if ($feedScope === 'circle')
                            <p class="text-[13.5px] text-ink-muted">Nothing from your friends or guilds yet.</p>
                            <p class="text-[12.5px] text-ink-subtle mt-1">
                                <a href="{{ route('friends.index') }}" wire:navigate class="text-gold hover:text-gold-light">Add the players you queue with</a>
                                or <a href="{{ route('guilds.index') }}" wire:navigate class="text-gold hover:text-gold-light">start a guild</a>
                                and their guides will show up here.
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

            {{-- Your guides, and the way to start one: no longer the page's main button. --}}
            <div>
                <div class="flex items-baseline justify-between gap-3 mb-2">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Your guides</h2>
                    @if ($this->myGuides->isNotEmpty())
                        <a href="{{ route('guides.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All &rarr;</a>
                    @endif
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
                <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1.5">
                    <button type="button" wire:click="createGuide('comp')" class="text-[12.5px] text-gold hover:text-gold-light">+ New comp guide</button>
                    <button type="button" wire:click="createGuide('class')" class="text-[12.5px] text-ink-subtle hover:text-gold">Class guide</button>
                </div>
            </div>

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
                        <p class="text-[12.5px] text-ink-subtle">Linked. We haven't found any characters yet.</p>
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

            <x-quizzes.leaderboard :rows="$this->quizLeaderboard"/>

            <p class="text-[12px] text-ink-subtle border-t border-line pt-4">
                Your handle is <span class="font-mono text-gold">&#64;{{ $me->handle() }}</span>.
                <a href="{{ route('friends.index') }}" wire:navigate class="hover:text-gold">Add a friend &rarr;</a>
            </p>
        </aside>
    </div>
</div>
