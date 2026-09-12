@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="font-display text-3xl text-ink">MindCollector Player Guides</h1>
        <p class="text-[14px] text-ink-muted mt-2 max-w-2xl">
            Openers, kill setups and matchup plans from arena players who run them. Filter by the
            class you play or the team you're up against.
        </p>
    </div>

    {{-- Sign-up / start-a-guide panel. Guests are pitched the account; signed-in players go
         straight to the builder. --}}
    <div class="linear-card border-line-gold p-5 mb-6 flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-1 min-w-0">
            <h2 class="text-[15px] font-semibold text-ink">Plan your games before you queue</h2>
            <p class="text-[13px] text-ink-muted mt-1 max-w-2xl">
                Build guides for your own comp. Map out your opener and CC chain, line up cooldowns
                with your teammates, and work out what to do in the matchups that keep beating you.
                Keep a plan private, share it with your team, or publish it here.
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @auth
                <a href="{{ route('guides.index') }}" wire:navigate class="btn-primary">Start a guide</a>
            @else
                <a href="{{ route('register') }}" class="btn-primary">Create a free account</a>
                <a href="{{ route('login') }}" class="btn-ghost">Log in</a>
            @endauth
        </div>
    </div>

    <div class="linear-card p-4 mb-6">
        {{-- Stacks on mobile: four controls forced onto one row squeeze each other into
             unreadable slivers on a phone. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto_auto] gap-3">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Search titles&hellip;"
                   class="form-input text-[13.5px]">

            <select wire:model.live="classSlug" class="form-select text-[13px]">
                <option value="">Playing any class</option>
                @foreach ($this->classes as $class)
                    <option value="{{ $class->slug }}">Playing {{ $class->name }}</option>
                @endforeach
            </select>

            {{-- The matchup half of the search. A guide's enemy team is what someone looking for
                 "how do I beat RMP" is actually searching for, and until now it was neither shown
                 on the card nor reachable by any filter. --}}
            <select wire:model.live="opponentClassSlug" class="form-select text-[13px]">
                <option value="">Against anyone</option>
                @foreach ($this->classes as $class)
                    <option value="{{ $class->slug }}">Against {{ $class->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sort" class="form-select text-[13px]">
                <option value="popular">Most liked</option>
                <option value="new">Newest</option>
            </select>
        </div>
    </div>

    <div wire:loading.class="opacity-60" wire:target="search,classSlug,opponentClassSlug,sort">
        @forelse ($this->guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors"/>
        @empty
            <div class="linear-card p-10 text-center">
                <p class="text-[13.5px] text-ink-muted">
                    @if ($search !== '' || $classSlug !== '' || $opponentClassSlug !== '')
                        Nothing matches that yet.
                    @else
                        No public guides yet. @auth<a href="{{ route('guides.index') }}" wire:navigate class="text-gold hover:text-gold-light">Write the first one.</a>@endauth
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->guides->links() }}</div>
</div>
