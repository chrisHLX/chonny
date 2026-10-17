{{-- MindCollector's home (PlatformController::home): what it is, and a card per game. --}}
<x-app-layout title="MindCollector: a coach that reads your own games"
              description="MindCollector reads the games you play, measures you against the players you actually meet, and tells you what to work on next. World of Warcraft arena now; StarCraft II and League of Legends next."
              og-image="/images/app/card.webp">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10 space-y-6">

    <section class="page-hero relative overflow-hidden"
             style="background: radial-gradient(circle at 85% 10%, #C8952C26, transparent 50%), radial-gradient(circle at 10% 100%, #3FA7F51a, transparent 45%)">
        <p class="page-eyebrow">MindCollector</p>
        <h1 class="page-title max-w-3xl">A coach that reads your own games.</h1>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            MindCollector reads the games you play, measures you against the players you actually meet, and tells you
            the one thing to work on next. Then it checks it again next session. It started with World of Warcraft arena,
            and StarCraft II is next.
        </p>
    </section>

    <section>
        <h2 class="text-[13px] font-bold text-ink-subtle uppercase tracking-[.12em] px-1 mb-3">Pick your game</h2>
        <div class="grid md:grid-cols-3 gap-4">
            @foreach ($games as $slug => $g)
                @php $live = $g['status'] === 'live'; @endphp
                <a href="{{ route($g['route']) }}" wire:navigate
                   class="linear-card group relative overflow-hidden p-5 flex flex-col {{ $live ? 'md:col-span-3 lg:col-span-1' : '' }} hover:-translate-y-0.5 transition-transform"
                   style="background: linear-gradient(160deg, {{ $g['color'] }}1f, transparent 55%)">
                    <div class="flex items-center justify-between">
                        <x-mc-icon :name="$g['icon']" class="w-11 h-11" style="color: {{ $g['color'] }}" />
                        @if ($live)
                            <span class="badge-green">Live</span>
                        @elseif (! empty($g['now']))
                            <span class="badge-blue">Quizzes open</span>
                        @else
                            <span class="badge-gray">Coming soon</span>
                        @endif
                    </div>
                    <h3 class="text-[18px] font-bold text-ink mt-4">{{ $g['name'] }}</h3>
                    <p class="text-[13.5px] text-ink-muted mt-2 flex-1">{{ $g['tagline'] }}</p>
                    @if ($live)
                        <img src="/images/app/card.webp" alt="A {{ $g['short'] }} game reviewed in MindCollector" loading="lazy"
                             class="mt-4 w-full rounded-lg border border-line shadow-xl">
                    @endif
                    <span class="mt-4 text-[13px] font-semibold group-hover:underline" style="color: {{ $g['color'] }}">
                        {{ $live ? 'Open '.$g['short'] : (empty($g['now']) ? 'See what\'s coming' : 'Try the quizzes') }} &rarr;
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="linear-card p-6">
        <h2 class="text-[17px] font-semibold text-ink">How it works</h2>
        <ol class="grid sm:grid-cols-3 gap-4 mt-4">
            @foreach ([
                ['Play as you normally do', 'The MindCollector app picks up each game when it ends. Nothing to start, nothing to export.'],
                ['Every game gets read', 'Who died and why, what was left unused, and how you compare with players of your spec at your rating.'],
                ['One thing to work on', 'The habit that costs you most, measured again next session so you can see it change.'],
            ] as $i => [$title, $body])
                <li class="rounded-xl bg-surface-2 p-4">
                    <span class="w-7 h-7 rounded-full bg-gold/15 text-gold text-[13px] font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                    <p class="text-[14px] font-semibold text-ink mt-3">{{ $title }}</p>
                    <p class="text-[13px] text-ink-muted mt-1">{{ $body }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</div>
</x-app-layout>
