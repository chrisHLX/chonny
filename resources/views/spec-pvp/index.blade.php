{{-- Every spec's arena guide (SpecPvpController::index). --}}
@php
    $breadcrumbs = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'PvP guides', 'item' => route('spec-pvp.index')],
        ],
    ];
@endphp
<x-app-layout :title="'WoW PvP Guides for Every Spec ('.$patch.') | MindCollector'"
              description="WoW arena guides for every spec, counted from real rated games: the PvP talents each spec takes, what they press when they go, how they survive, what to kick, and the 3v3 comps they play."
              og-image="/images/app/card.webp" :schema="$breadcrumbs">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10 space-y-5">
    <section class="page-hero">
        <p class="page-eyebrow">{{ $patch }} arena</p>
        <h1 class="page-title">WoW PvP guides for every spec</h1>
        <p class="text-[15px] text-ink-muted mt-3 max-w-2xl">
            Each guide is counted from real rated arena games: the PvP talents a spec takes, what it presses when it goes,
            the defensives it uses, what to kick on it, and the comps it plays.
        </p>
    </section>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach ($classes as $className => $specs)
            @php
                $class = $specs->first()->gameClass;
                $color = $classColors[$class->slug] ?? '#B3B3B3';
            @endphp
            <section id="{{ $class->slug }}" class="linear-card p-4" style="background: linear-gradient(160deg, {{ $color }}14, transparent 50%)">
                <h2 class="flex items-center gap-2.5 text-[14px] font-semibold" style="color: {{ $color }}">
                    <x-class-icon :class="$class" size="w-7 h-7" />
                    {{ $className }}
                </h2>
                <ul class="mt-3 space-y-1">
                    @foreach ($specs as $s)
                        <li>
                            <a href="{{ route('spec-pvp.show', [$class->slug, $s->slug]) }}"
                               class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 -mx-2 text-[13px] text-ink hover:bg-surface-2 transition-colors">
                                <x-spec-icon :spec="$s" :color="$color" size="w-7 h-7" />
                                <span>{{ $s->name }} {{ $className }} <span class="text-ink-subtle">PvP guide</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <section class="linear-card p-6 overflow-hidden">
        <div class="grid md:grid-cols-[1fr_17rem] gap-6 items-center">
            <div>
                <h2 class="text-[17px] font-semibold text-ink">Get this for your own games</h2>
                <p class="text-[14px] text-ink-muted mt-2">
                    The free MindCollector app reviews every arena game you play and compares you with the players of your spec.
                </p>
                <a href="{{ route('app') }}" class="btn-primary !px-6 !py-2.5 mt-4 inline-flex">Get the app for Windows</a>
            </div>
            <img src="/images/app/improve.webp" alt="Where you stand, in the MindCollector app" loading="lazy"
                 class="w-full rounded-xl border border-line shadow-2xl">
        </div>
    </section>
</div>
</x-app-layout>
