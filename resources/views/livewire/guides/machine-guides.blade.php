@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-5xl mx-auto px-4 py-8">

    {{-- What these are, said before the list rather than under it. A reader has to be able to tell
         at a glance that this is a model's draft and not a player's plan, and that correcting it is
         the point rather than an afterthought. --}}
    <div class="mb-5">
        <p class="text-[11px] uppercase tracking-[0.16em] text-gold font-medium mb-2">Drafted by a model</p>
        <h1 class="font-display text-3xl text-ink">Claude's Comp Guides</h1>
        <p class="text-[14px] text-ink-muted mt-1.5 max-w-2xl leading-relaxed">
            Comp plans written by Claude from MindCollector's game data and real match windows.
            Cooldowns, diminishing returns and immunities are derived and live.
            <span class="text-ink">The plan on top of them is a guess.</span>
        </p>
    </div>

    <div class="linear-card border-line-gold p-4 mb-6">
        <p class="text-[13.5px] text-ink">
            <span class="text-gold font-semibold">Tell it where it's wrong.</span>
            Every step takes a note — open a guide and say what a real player would do instead, and why.
        </p>
        <p class="text-[12px] text-ink-muted mt-1.5">
            Those notes are read and used to correct the model these are generated from, so a correction
            here changes every guide that comes after it.
            @guest
                <a href="{{ route('register') }}" class="text-gold hover:text-gold-light">Sign up</a> to add one.
            @endguest
        </p>
    </div>

    <div class="space-y-3">
        @forelse ($this->guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors"/>
        @empty
            <div class="linear-card p-10 text-center">
                <p class="text-[14px] text-ink-muted">No machine-drafted guides yet.</p>
            </div>
        @endforelse
    </div>

    <p class="text-[11.5px] text-ink-subtle mt-8 pt-6 border-t border-line leading-relaxed max-w-prose">
        Kept separate from <a href="{{ route('guides.browse') }}" wire:navigate class="text-ink-muted hover:text-gold">Player Guides</a>
        on purpose: those are written by people who play these comps. These are not, and shouldn't
        compete with them on the same page.
    </p>
</div>
