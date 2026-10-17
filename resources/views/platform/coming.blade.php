{{-- A game that is coming to MindCollector (PlatformController::game): what is open already, what
     it will have, and "tell me when it's ready". --}}
<x-app-layout :title="$game['name'].' on MindCollector: '.($now ? 'quizzes, and more coming' : 'coming soon')"
              :description="$game['name'].' on MindCollector: '.$game['tagline'].' Ask to be told when it opens.'">
<div class="max-w-4xl mx-auto px-4 py-6 sm:py-10 space-y-5">

    <nav class="text-[12px] text-ink-subtle">
        <a href="{{ route('home') }}" class="hover:text-ink">MindCollector</a>
        <span class="mx-1">/</span>
        <span class="text-ink-muted">{{ $game['name'] }}</span>
    </nav>

    <section class="page-hero relative overflow-hidden" style="background: radial-gradient(circle at 90% 0%, {{ $game['color'] }}2e, transparent 55%)">
        <div class="flex items-start gap-4 sm:gap-5">
            <x-mc-icon :name="$game['icon']" class="w-14 h-14 sm:w-20 sm:h-20 shrink-0" style="color: {{ $game['color'] }}" />
            <div class="min-w-0">
                <p class="page-eyebrow" style="color: {{ $game['color'] }}">{{ $now ? 'Quizzes open now, more coming' : 'Coming soon' }}</p>
                <h1 class="page-title">{{ $game['name'] }}</h1>
            </div>
        </div>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">{{ $game['tagline'] }}</p>
    </section>

    @if ($now)
        <section>
            <h2 class="text-[13px] font-bold text-ink-subtle uppercase tracking-[.12em] px-1 mb-3">Open now</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ($now as $n)
                    <a href="{{ $n['url'] }}" class="linear-card p-5 flex flex-col hover:-translate-y-0.5 transition-transform"
                       style="background: linear-gradient(160deg, {{ $game['color'] }}26, transparent 60%)">
                        <h3 class="text-[16px] font-semibold text-ink">{{ $n['title'] }}</h3>
                        <p class="text-[13px] text-ink-muted mt-1.5 flex-1">{{ $n['body'] }}</p>
                        <span class="mt-3 text-[13px] font-semibold" style="color: {{ $game['color'] }}">{{ $n['cta'] }} &rarr;</span>
                    </a>
                @endforeach
            </div>
        </section>

        <h2 class="text-[13px] font-bold text-ink-subtle uppercase tracking-[.12em] px-1 -mb-2">Coming</h2>
    @endif

    {{-- Whole class names: Tailwind only builds a class it can read in full in the source. --}}
    <section class="grid {{ count($game['coming']) >= 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-3">
        @foreach ($game['coming'] as $c)
            <div class="linear-card p-5">
                <span class="block w-8 h-1 rounded-full" style="background: {{ $game['color'] }}"></span>
                <h2 class="text-[15px] font-semibold text-ink mt-3">{{ $c['title'] }}</h2>
                <p class="text-[13px] text-ink-muted mt-1.5">{{ $c['body'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="linear-card p-6" id="notify">
        <h2 class="text-[17px] font-semibold text-ink">Tell me when it's ready</h2>
        @if (session('interest_status'))
            <p class="text-[14px] text-emerald-300 mt-2">{{ session('interest_status') }}</p>
        @elseif ($waiting)
            <p class="text-[14px] text-ink-muted mt-2">You're on the list. We'll tell you when {{ $game['name'] }} opens.</p>
        @else
            <p class="text-[14px] text-ink-muted mt-2">One message when {{ $game['name'] }} opens on MindCollector. Nothing else.</p>
            <form method="POST" action="{{ route('game.notify', $slug) }}" class="mt-4 flex flex-col sm:flex-row gap-2 max-w-lg">
                @csrf
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                @guest
                    <input type="email" name="email" required placeholder="Your email" value="{{ old('email') }}" class="form-input flex-1">
                @endguest
                <button type="submit" class="btn-primary !px-6">{{ auth()->check() ? 'Tell me when it opens' : 'Notify me' }}</button>
            </form>
            @error('email')<p class="text-[12px] text-red-400 mt-2">{{ $message }}</p>@enderror
        @endif
    </section>

    <section class="linear-card p-5 flex flex-col sm:flex-row sm:items-center gap-4"
             style="background: linear-gradient(135deg, {{ $games['wow']['color'] }}1a, transparent 60%)">
        <x-mc-icon :name="$games['wow']['icon']" class="w-10 h-10 shrink-0" style="color: {{ $games['wow']['color'] }}" />
        <div class="flex-1">
            <p class="text-[14px] font-semibold text-ink">Play World of Warcraft too?</p>
            <p class="text-[13px] text-ink-muted mt-0.5">Arena is open now: every game reviewed, and PvP guides for every spec.</p>
        </div>
        <a href="{{ route('wow.home') }}" wire:navigate class="btn-secondary !px-5 shrink-0">Open WoW</a>
    </section>
</div>
</x-app-layout>
