@php
    use App\Enums\UserGuideStatus;

    $classColors = config('wow_classes.colors', []);
    $me = auth()->user();
@endphp

<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    {{-- Greeting --}}
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.16em] text-gold font-medium mb-1.5">MindCollector</p>
        <h1 class="font-display text-[26px] sm:text-3xl text-ink leading-tight">
            Welcome back, {{ $me->name }}
        </h1>
        <p class="text-[13.5px] text-ink-muted mt-1.5 max-w-prose">
            Plan your opener and your go with the people you queue with, then share it.
        </p>
    </div>

    {{-- The three things to do ------------------------------------------------------ --}}
    <div class="grid gap-3 md:grid-cols-3 mb-8">

        {{-- Build a guide: the primary action, so it carries the gold. --}}
        <div class="linear-card p-5 flex flex-col border-line-gold relative overflow-hidden">
            <x-ornament.corner position="tr" class="absolute top-2 right-2 w-8 h-8 text-gold/20"/>
            <div class="flex items-center gap-2 mb-2">
                <x-mc-icon name="icon-scroll" class="w-5 h-5 text-gold"/>
                <h2 class="text-[15px] font-semibold text-ink">Build a guide</h2>
            </div>
            <p class="text-[13px] text-ink-muted mb-4 flex-1">
                Drag your abilities into an opener or a go. We work out how much control survives
                diminishing returns and how often you can run it again.
            </p>
            <div class="flex flex-col sm:flex-row md:flex-col lg:flex-row gap-2">
                <button type="button" wire:click="createGuide('comp')" class="btn-primary justify-center flex-1">3v3 / 2v2 guide</button>
                <button type="button" wire:click="createGuide('class')" class="btn-secondary justify-center flex-1">Class guide</button>
            </div>
            @if ($draft = $this->myGuides->firstWhere('status', UserGuideStatus::Draft))
                <a href="{{ route('guides.edit', $draft->slug) }}" wire:navigate
                   class="text-[12px] text-ink-subtle hover:text-gold mt-3 truncate">
                    Continue &ldquo;{{ $draft->title }}&rdquo; &rarr;
                </a>
            @endif
        </div>

        {{-- Battle.net --}}
        <div class="linear-card p-5 flex flex-col">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-5 h-5 text-[#148EFF]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 3.2a6.8 6.8 0 110 13.6 6.8 6.8 0 010-13.6zm0 2.4a4.4 4.4 0 100 8.8 4.4 4.4 0 000-8.8z"/>
                </svg>
                <h2 class="text-[15px] font-semibold text-ink">Your characters</h2>
            </div>

            @if ($this->hasBattlenet)
                @if ($this->characters->isEmpty())
                    <p class="text-[13px] text-ink-muted flex-1">Battle.net is linked, but we haven't found any characters yet.</p>
                @else
                    <div class="flex flex-col gap-2 flex-1 mb-3">
                        @foreach ($this->characters as $character)
                            @php $exp = $character->bestExp(); @endphp
                            <a href="{{ route('characters.show', $character->id) }}" wire:navigate wire:key="char-{{ $character->id }}"
                               class="flex items-center gap-2.5 rounded px-1.5 py-1 -mx-1.5 hover:bg-surface-2 transition-colors">
                                @if ($character->specialization)
                                    <x-spec-icon :spec="$character->specialization" size="w-7 h-7"/>
                                @endif
                                <span class="flex-1 min-w-0">
                                    <span class="block text-[13px] font-medium truncate" style="color: {{ $classColors[$character->gameClass?->slug] ?? '#8A8A9A' }}">{{ $character->name }}</span>
                                    <span class="block text-[11px] text-ink-subtle truncate">{{ $character->realm_name }}</span>
                                </span>
                                @if ($exp)
                                    <span class="text-[12px] text-gold tabular-nums shrink-0">{{ $exp['rating'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
                <a href="{{ route('characters.index') }}" wire:navigate class="btn-ghost justify-center">All characters</a>
            @else
                <p class="text-[13px] text-ink-muted mb-4 flex-1">
                    Link Battle.net to bring in your characters, ratings and talents &mdash; and sign
                    your guides with the character you play them on.
                </p>
                @if ($this->battlenetAvailable)
                    <a href="{{ route('battlenet.redirect') }}" class="btn-secondary justify-center">Link Battle.net</a>
                @else
                    <p class="text-[12px] text-ink-subtle">Battle.net linking isn't available right now.</p>
                @endif
            @endif
        </div>

        {{-- Comps --}}
        <div class="linear-card p-5 flex flex-col">
            <div class="flex items-center gap-2 mb-2">
                <x-mc-icon name="icon-compass" class="w-5 h-5 text-violet"/>
                <h2 class="text-[15px] font-semibold text-ink">Browse 3v3 comps</h2>
            </div>
            <p class="text-[13px] text-ink-muted mb-3">
                Put any three specs side by side: their CC, cooldowns, burst windows and counters.
            </p>
            <div class="flex flex-col gap-1.5 flex-1 mb-3">
                @foreach ($this->presetComps as $preset)
                    <a href="{{ route('wow-comps', ['preset' => $preset['key']]) }}" wire:key="preset-{{ $preset['key'] }}"
                       class="flex items-center gap-2.5 rounded px-1.5 py-1 -mx-1.5 hover:bg-surface-2 transition-colors">
                        <span class="flex items-center gap-1">
                            @foreach ($preset['specs'] as $spec)
                                <x-spec-icon :spec="$spec" size="w-6 h-6"/>
                            @endforeach
                        </span>
                        <span class="text-[13px] text-ink">{{ $preset['label'] }}</span>
                    </a>
                @endforeach
            </div>
            <a href="{{ route('wow-comps') }}" class="btn-ghost justify-center">Open the comp builder</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Main column ------------------------------------------------------------- --}}
        <div class="lg:col-span-2 space-y-8 min-w-0">

            {{-- Your guides --}}
            <section>
                <div class="flex items-baseline justify-between gap-4 mb-3">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Your guides</h2>
                    <a href="{{ route('guides.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All your guides &rarr;</a>
                </div>

                @forelse ($this->myGuides as $guide)
                    <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate wire:key="mine-{{ $guide->id }}"
                       class="linear-card p-3 mb-2 flex items-center gap-3 hover:border-line-gold transition-colors">
                        <span class="flex items-center gap-1 shrink-0">
                            @forelse ($guide->members as $member)
                                @if ($member->specialization)
                                    <x-spec-icon :spec="$member->specialization" size="w-7 h-7"/>
                                @endif
                            @empty
                                <span class="w-7 h-7 rounded-md border border-dashed border-line-strong"></span>
                            @endforelse
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="block text-[14px] font-medium text-ink truncate">{{ $guide->title }}</span>
                            <span class="block text-[11.5px] text-ink-subtle">edited {{ $guide->updated_at->diffForHumans() }}</span>
                        </span>
                        <span class="{{ $guide->status === UserGuideStatus::Published ? 'badge-green' : 'badge-gray' }} shrink-0">
                            {{ $guide->status === UserGuideStatus::Published ? 'Published' : 'Draft' }}
                        </span>
                    </a>
                @empty
                    <div class="linear-card p-5 text-center">
                        <p class="text-[13.5px] text-ink-muted">You haven't written a guide yet. Start with the opener you run most.</p>
                    </div>
                @endforelse
            </section>

            {{-- You can help edit --}}
            @if ($this->collaborating->isNotEmpty())
                <section>
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-1">You can help edit</h2>
                    <p class="text-[12px] text-ink-subtle mb-3">Friends and guildmates opened these to you. What you add is credited to you.</p>
                    @foreach ($this->collaborating as $guide)
                        <a href="{{ route('guides.edit', $guide->slug) }}" wire:navigate wire:key="collab-{{ $guide->id }}"
                           class="linear-card p-3 mb-2 flex items-center gap-3 hover:border-violet/60 transition-colors">
                            <span class="flex items-center gap-1 shrink-0">
                                @foreach ($guide->members as $member)
                                    @if ($member->specialization)
                                        <x-spec-icon :spec="$member->specialization" size="w-7 h-7"/>
                                    @endif
                                @endforeach
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-[14px] font-medium text-ink truncate">{{ $guide->title }}</span>
                                <span class="block text-[11.5px] text-ink-subtle">by &#64;{{ $guide->user?->handle() }}</span>
                            </span>
                            <span class="text-[12px] text-violet shrink-0">Edit &rarr;</span>
                        </a>
                    @endforeach
                </section>
            @endif

            {{-- From your friends and guilds --}}
            <section>
                <div class="flex items-baseline justify-between gap-4 mb-3">
                    <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">From your friends &amp; guilds</h2>
                    <a href="{{ route('friends.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">Friends &rarr;</a>
                </div>

                @forelse ($this->circleGuides as $guide)
                    <x-guides.card :guide="$guide" :class-colors="$classColors" :compact="true" wire:key="circle-{{ $guide->id }}"/>
                @empty
                    <div class="linear-card p-5">
                        <p class="text-[13.5px] text-ink-muted">
                            Nothing from your people yet.
                            <a href="{{ route('friends.index') }}" wire:navigate class="text-gold hover:text-gold-light">Add the players you queue with</a>
                            or <a href="{{ route('guilds.index') }}" wire:navigate class="text-gold hover:text-gold-light">start a guild</a>
                            &mdash; their guides show up here, and you can work on guides together.
                        </p>
                    </div>
                @endforelse
            </section>

            {{-- Popular --}}
            @if ($this->popularGuides->isNotEmpty())
                <section>
                    <div class="flex items-baseline justify-between gap-4 mb-3">
                        <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold">Popular player guides</h2>
                        <a href="{{ route('guides.browse') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">Browse all &rarr;</a>
                    </div>
                    @foreach ($this->popularGuides as $guide)
                        <x-guides.card :guide="$guide" :class-colors="$classColors" :compact="true" wire:key="pop-{{ $guide->id }}"/>
                    @endforeach
                </section>
            @endif
        </div>

        {{-- Side column ------------------------------------------------------------- --}}
        <aside class="space-y-4 min-w-0">

            {{-- Friends --}}
            <div class="linear-card p-4">
                <div class="flex items-baseline justify-between gap-3 mb-3">
                    <h2 class="text-[13px] font-semibold text-ink">Friends</h2>
                    <a href="{{ route('friends.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">Manage</a>
                </div>

                @forelse ($this->friendRequests as $request)
                    <div class="flex items-center gap-2 mb-2" wire:key="req-{{ $request->id }}">
                        <p class="flex-1 min-w-0 text-[13px] text-ink truncate">
                            &#64;{{ $request->requester?->handle() }} <span class="text-ink-subtle">wants to be friends</span>
                        </p>
                        <button type="button" wire:click="acceptFriend({{ $request->id }})" class="btn-primary text-[12px] px-2.5 py-1 shrink-0">Accept</button>
                        <button type="button" wire:click="declineFriend({{ $request->id }})" class="text-ink-subtle hover:text-red-400 px-1 shrink-0" title="Decline">&times;</button>
                    </div>
                @empty
                    <p class="text-[12.5px] text-ink-muted mb-3">
                        {{ $me->friendIds()->count() }} {{ Str::plural('friend', $me->friendIds()->count()) }}.
                        Your handle is <span class="font-mono text-gold">&#64;{{ $me->handle() }}</span>.
                    </p>
                    <a href="{{ route('friends.index') }}" wire:navigate class="btn-ghost w-full justify-center">Add a friend</a>
                @endforelse
            </div>

            {{-- Guilds --}}
            <div class="linear-card p-4">
                <div class="flex items-baseline justify-between gap-3 mb-3">
                    <h2 class="text-[13px] font-semibold text-ink">Guilds</h2>
                    <a href="{{ route('guilds.index') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">
                        {{ $this->guilds->isEmpty() ? 'Start one' : 'All' }}
                    </a>
                </div>
                @forelse ($this->guilds as $guild)
                    <a href="{{ route('guilds.show', $guild) }}" wire:navigate wire:key="guild-{{ $guild->id }}"
                       class="flex items-center justify-between gap-2 py-1 text-[13px] text-ink hover:text-gold transition-colors">
                        <span class="truncate">{{ $guild->name }}</span>
                        <span class="text-[11px] text-ink-subtle tabular-nums shrink-0">{{ $guild->members_count }}</span>
                    </a>
                @empty
                    <p class="text-[12.5px] text-ink-muted">A guild shares guides with your whole team at once &mdash; join by invite link.</p>
                @endforelse
            </div>

            {{-- Training: the old dashboard, one link away --}}
            <a href="{{ route('training') }}" class="linear-card p-4 block hover:border-line-strong transition-colors">
                <div class="flex items-center gap-2 mb-1">
                    <x-mc-icon name="icon-flask" class="w-4 h-4 text-ink-subtle"/>
                    <h2 class="text-[13px] font-semibold text-ink">Training</h2>
                </div>
                <p class="text-[12.5px] text-ink-muted">Your arena diagnostic, practice quizzes and progress.</p>
            </a>
        </aside>
    </div>
</div>
