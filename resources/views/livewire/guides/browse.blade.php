@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">Player guides</p>
        <h1 class="font-display text-3xl text-ink">Written by players</h1>
        <p class="text-[13.5px] text-ink-muted mt-1 max-w-2xl">
            Comps, openers and matchups, written and rated by the people who play them. Distinct from
            the guides derived from real match data elsewhere on this site.
        </p>
    </div>

    <div class="linear-card p-4 mb-6">
        <div class="grid sm:grid-cols-[1fr_auto_auto] gap-3">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Search titles&hellip;"
                   class="form-input text-[13.5px]">

            <select wire:model.live="classSlug" class="form-select text-[13px]">
                <option value="">Any class</option>
                @foreach ($this->classes as $class)
                    <option value="{{ $class->slug }}">{{ $class->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sort" class="form-select text-[13px]">
                <option value="rating">Best rated</option>
                <option value="new">Newest</option>
            </select>
        </div>
    </div>

    <div wire:loading.class="opacity-60" wire:target="search,classSlug,sort">
        @forelse ($this->guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors"/>
        @empty
            <div class="linear-card p-10 text-center">
                <p class="text-[13.5px] text-ink-muted">
                    @if ($search !== '' || $classSlug !== '')
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
