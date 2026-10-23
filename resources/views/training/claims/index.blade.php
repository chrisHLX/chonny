<x-app-layout title="Claims | Training | MindCollector">
<div class="tr max-w-6xl mx-auto px-4 py-6 sm:py-10 space-y-8">
    @include('training.partials.top')
    <header class="max-w-3xl space-y-3">
        <h1 class="font-display font-extrabold tracking-tight text-ink text-3xl sm:text-4xl leading-[1.05]">Examine a claim, then find out</h1>
        <p class="text-[15px] text-ink-muted">Something you read, heard from a teammate, or got from an AI. Work it through the four questions, say how confident you are, and come back later to record what turned out to be true.</p>
        <a class="btn-primary" href="{{ route('training.claims.create') }}">Examine a new claim</a>
    </header>
    @foreach ([['Due: what happened?', $due], ['Waiting', $open], ['Resolved', $closed]] as [$heading, $group])
        @if ($group->isNotEmpty())
            <section class="space-y-3">
                <div class="flex items-baseline justify-between gap-3"><h2 class="text-ink font-bold text-xl">{{ $heading }}</h2><span class="text-[12px] text-ink-subtle">{{ $group->count() }}</span></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($group as $c)
                        <a class="linear-card p-4 space-y-2 block" href="{{ route('training.claims.show', $c) }}">
                            <p class="text-[12px] text-ink-subtle">{{ $c->created_at->format('j M Y') }} · {{ $c->confidence_before }}% → {{ $c->confidence_after }}% confident</p>
                            <p class="text-[14.5px] text-ink">{{ Str::limit($c->claim, 180) }}</p>
                            @if ($c->outcome)<span class="badge-green">{{ \App\Models\Training\Claim::OUTCOMES[$c->outcome] }}</span>@elseif ($c->isDue())<span class="badge-amber">Due</span>@else<span class="badge-gray">Back {{ $c->revisit_on->format('j M') }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
</x-app-layout>
