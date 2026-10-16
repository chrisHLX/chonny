{{-- Every spec's arena guide (SpecPvpController::index). --}}
@php
    $total = collect($data['specs'] ?? [])->sum('rounds');
    $breadcrumbs = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'PvP guides', 'item' => route('spec-pvp.index')],
        ],
    ];
@endphp
<x-app-layout :title="'WoW PvP Guides for Every Spec ('.$patch.') | MindCollector'"
              description="WoW arena guides for every spec, counted from real rated games: the PvP talents each spec takes, what they press when they go, how they survive, and the 3v3 comps they play."
              og-image="/images/app/card.webp" :schema="$breadcrumbs">
<div class="max-w-4xl mx-auto px-4 py-6 sm:py-10 space-y-5">
    <section class="page-hero">
        <p class="page-eyebrow">{{ $patch }} arena</p>
        <h1 class="page-title">WoW PvP guides for every spec</h1>
        <p class="text-[15px] text-ink-muted mt-3 max-w-2xl">
            Each guide is counted from real rated arena games ({{ number_format($total) }} player-rounds so far):
            the PvP talents a spec takes, what it presses when it goes, the defensives it uses, and the comps it plays.
        </p>
    </section>

    <div class="grid sm:grid-cols-2 gap-3">
        @foreach ($classes as $className => $specs)
            @php $slug = $specs->first()->gameClass->slug; @endphp
            <section id="{{ $slug }}" class="linear-card p-4">
                <h2 class="text-[14px] font-semibold" style="color: {{ $classColors[$slug] ?? '#B3B3B3' }}">{{ $className }}</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($specs as $s)
                        @php $d = $data['specs'][(string) $s->external_spec_id]; @endphp
                        <li class="flex items-baseline justify-between gap-3 text-[13px]">
                            <a href="{{ route('spec-pvp.show', [$slug, $s->slug]) }}" class="text-ink hover:text-gold">{{ $s->name }} {{ $className }} PvP guide</a>
                            <span class="text-[11px] text-ink-subtle tabular-nums whitespace-nowrap">{{ number_format($d['rounds']) }} rounds</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <section class="linear-card p-6" >
        <h2 class="text-[17px] font-semibold text-ink">Get this for your own games</h2>
        <p class="text-[14px] text-ink-muted mt-2 max-w-2xl">
            The free MindCollector app reviews every arena game you play and compares you with the players of your spec.
        </p>
        <a href="{{ route('app') }}" class="btn-primary !px-6 !py-2.5 mt-4 inline-flex">Get the app for Windows</a>
    </section>
</div>
</x-app-layout>
