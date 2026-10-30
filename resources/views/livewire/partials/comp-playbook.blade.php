{{-- "How to play it": WowComps' plain guide for a player new to arena (CompPlaybookService).
     Expects $comp, $playbook (null until all three slots are filled), $drBadge, $openSpell, and
     optionally $part: 'plan' (the "How to play it" tab: the team's plan only, so it starts at the
     top), 'basics' (the Learn tab: PvP guides and class quizzes), or both when absent (nothing picked yet).
     Offensive cooldowns and crowd control are separate sections on purpose (guide-writing.md):
     two jobs, often two players, and a reader looks for them separately. --}}
@php
    $who = fn ($mi) => $mi !== null && isset($comp[$mi]['spec'])
        ? $comp[$mi]['spec']->name.' '.$comp[$mi]['class']->name
        : null;
    $colorOf = fn ($mi) => $mi !== null && isset($comp[$mi]['class'])
        ? (config('wow_classes.colors')[$comp[$mi]['class']->slug] ?? '#8A8A9A')
        : '#8A8A9A';
    $secs = fn ($s) => $s !== null ? rtrim(rtrim(number_format((float) $s, 1), '0'), '.').'s' : null;
    $every = fn ($s) => $s >= 60 && fmod((float) $s, 60) == 0 ? ($s / 60).' min' : $secs($s);
    // One clickable ability: icon, name, and the shared spell modal for its owner's build.
    $chip = function ($spell, $mi, ?string $extra = null) use ($comp, $openSpell, $colorOf) {
        $member = $comp[$mi] ?? null;
        $icon = $spell->icon_name ? '<img src="/storage/spell-icons/'.e($spell->icon_name).'" alt="" loading="lazy" class="w-5 h-5 rounded border border-line object-cover">' : '<span class="w-5 h-5 rounded border border-line-strong bg-surface-2 inline-block"></span>';

        return '<button type="button" x-on:click="'.$openSpell($member, $spell->id).'" class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface-2 hover:border-line-strong px-2 py-1 text-[12px] text-ink transition-colors" style="border-left: 2px solid '.e($colorOf($mi)).'">'
            .$icon.'<span>'.e($spell->display_name).'</span>'
            .($extra ? '<span class="text-[10px] text-ink-subtle">'.e($extra).'</span>' : '')
            .'</button>';
    };
@endphp

@php $part ??= 'all'; @endphp
<div class="space-y-4">
    @if ($part !== 'plan')
    {{-- Learn your spec (2026-10-30, Chriso: "get rid of the new to arena check list just have links to
         the class quizes and PvP guides in a nice format"): the two places that teach a spec, and each
         picked spec's own guide and quiz. The eight-line checklist it replaces is in git history. --}}
    @php
        $picked = collect($comp ?? [])->filter(fn ($m) => ! empty($m['spec']) && ! empty($m['class']))->values();
    @endphp
    <div class="linear-card p-5">
        <p class="text-[11px] uppercase tracking-wide text-gold font-semibold">Learn your spec</p>
        <div class="grid sm:grid-cols-2 gap-3 mt-3">
            <a href="{{ route('spec-pvp.index') }}" wire:navigate class="group flex items-start gap-3 rounded-xl border border-line bg-surface-2 hover:border-line-gold p-4 transition-colors">
                <span class="w-10 h-10 rounded-lg bg-gold-subtle border border-line-gold flex items-center justify-center text-gold shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-[15px] font-semibold text-ink group-hover:text-gold-light">PvP guides</span>
                    <span class="block text-[12.5px] text-ink-muted mt-0.5">Every spec's PvP talents, goes, defensives, what to kick and best comps, counted from real games.</span>
                    <span class="inline-block text-[12px] text-gold mt-2">Find your spec &rarr;</span>
                </span>
            </a>
            <a href="{{ route('wow-quiz') }}" wire:navigate class="group flex items-start gap-3 rounded-xl border border-line bg-surface-2 hover:border-violet p-4 transition-colors">
                <span class="w-10 h-10 rounded-lg bg-violet-subtle border border-violet-muted flex items-center justify-center text-violet shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-[15px] font-semibold text-ink group-hover:text-violet-hover">Class quizzes</span>
                    <span class="block text-[12.5px] text-ink-muted mt-0.5">Questions built from the live game data: your cooldowns, your crowd control, what counters it.</span>
                    <span class="inline-block text-[12px] text-violet mt-2">Take a quiz &rarr;</span>
                </span>
            </a>
        </div>
        @if ($picked->isNotEmpty())
            <p class="text-[11px] uppercase tracking-wide text-ink-subtle font-semibold mt-5 mb-2">This team</p>
            <div class="divide-y divide-line rounded-xl border border-line overflow-hidden">
                @foreach ($picked as $m)
                    @php
                        $guide = \App\Support\SpecPages::forSpec((int) $m['spec']->external_spec_id);
                        $color = config('wow_classes.colors')[$m['class']->slug] ?? '#B3B3B3';
                    @endphp
                    <div class="flex items-center gap-3 px-3 py-2.5 bg-surface-1">
                        <x-spec-icon :spec="$m['spec']" size="w-7 h-7"/>
                        <span class="flex-1 min-w-0 truncate text-[13px] font-semibold" style="color: {{ $color }}">{{ $m['spec']->name }} {{ $m['class']->name }}</span>
                        @if ($guide)
                            <a href="{{ route('spec-pvp.show', [$m['class']->slug, $m['spec']->slug]) }}" wire:navigate class="shrink-0 rounded-full border border-line-gold bg-gold-subtle text-gold-light hover:border-gold px-3 py-1 text-[12px] font-semibold">Guide</a>
                        @endif
                        <a href="{{ route('wow-quiz', [$m['class']->slug, $m['spec']->slug]) }}" wire:navigate class="shrink-0 rounded-full border border-violet-muted bg-violet-subtle text-violet hover:border-violet px-3 py-1 text-[12px] font-semibold">Quiz</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @endif

    @if ($part === 'basics')
    @elseif (! $playbook)
        {{-- Empty slots stay empty on open (WowComps::PRESET_COMPS says why); the examples are one
             click away instead, so the first look at the page can show it working (2026-10-18). --}}
        <div class="linear-card p-5">
            <p class="text-[13px] text-ink-muted">
                Pick a spec for all three slots to see how that team plays: who locks their healer, which buttons go together, and what to save.
            </p>
            @if (! empty($presets))
                <p class="text-[13px] text-ink mt-4">Or see a common comp now:</p>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach ($presets as $preset)
                        <button type="button" wire:click="applyPreset('{{ $preset['key'] }}')" wire:key="empty-preset-{{ $preset['key'] }}"
                                class="btn-secondary !py-2 flex items-center gap-2">
                            <span class="flex -space-x-1">@foreach ($preset['specs'] as $spec)<x-spec-icon :spec="$spec" size="w-5 h-5"/>@endforeach</span>
                            {{ $preset['label'] }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Your go, part 1: the crowd control. --}}
        <div class="linear-card p-5">
            <p class="text-[11px] uppercase tracking-wide text-gold font-semibold">Your go, part 1</p>
            <h3 class="text-[16px] text-ink font-semibold mt-0.5">
                Lock their healer
                @if ($playbook['lock']['seconds'] > 0)
                    <span class="text-ink-muted font-normal">for about {{ $secs($playbook['lock']['seconds']) }}</span>
                @endif
            </h3>
            @if (empty($playbook['lock']['steps']))
                <p class="text-[13px] text-ink-subtle mt-2">This team has no crowd control in the data yet.</p>
            @else
                <p class="text-[12px] text-ink-subtle mt-1">Each player's usual combo on the healer in real games, put together. One after another, each as the last one ends, a different kind each time so none is shortened.</p>
                <ol class="mt-3 space-y-3">
                    @foreach ($playbook['lock']['steps'] as $i => $step)
                        <li class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-gold-subtle border border-line-gold text-gold text-[12px] font-semibold flex items-center justify-center flex-shrink-0">{{ $i + 1 }}</span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach ($step['options'] as $oi => $o)
                                        @if ($oi > 0)
                                            <span class="text-[12px] text-ink-subtle">or</span>
                                        @endif
                                        {!! $chip($o['spell'], $o['mi'], $who($o['mi']).($o['split'] ? ' · both ways' : '')) !!}
                                    @endforeach
                                    <span class="{{ $drBadge[$step['dr']] ?? 'badge-gray' }}">{{ $step['dr'] }}</span>
                                    @if ($step['seconds'])
                                        <span class="text-[12px] text-ink-muted">{{ $secs($step['seconds']) }}</span>
                                    @endif
                                </div>
                                @if ($step['notes'])
                                    <p class="text-[12px] text-ink-muted mt-1">{{ implode(' ', $step['notes']) }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- Your go, part 2: the damage. --}}
        <div class="linear-card p-5">
            <p class="text-[11px] uppercase tracking-wide text-gold font-semibold">Your go, part 2</p>
            <h3 class="text-[16px] text-ink font-semibold mt-0.5">Press these together while their healer is locked</h3>
            <div class="mt-3 space-y-3">
                @foreach ($playbook['burst']['players'] as $p)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[12px] font-medium w-44 flex-shrink-0" style="color: {{ $colorOf($p['mi']) }}">{{ $who($p['mi']) }}</span>
                        @forelse ($p['buttons'] as $b)
                            {!! $chip($b['entry']['spell'], $p['mi'], $secs($b['entry']['cooldown']['seconds'] ?? null)) !!}
                        @empty
                            <span class="text-[12px] text-ink-subtle">Not enough of this spec's games measured yet to say which buttons it goes with. Its cooldowns are on the Offensive Cooldowns tab.</span>
                        @endforelse
                        @if ($p['healer'])
                            <span class="text-[11px] text-ink-subtle">your healer, in most of its goes</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($playbook['burst']['every'])
                <p class="text-[12px] text-ink-muted mt-4">
                    Everything lines up again about every {{ $every($playbook['burst']['every']) }}.
                    @if ($playbook['burst']['fastest'])
                        The {{ $every($playbook['burst']['fastest']) }} buttons come back in between, for smaller goes.
                    @endif
                </p>
            @endif
            <p class="text-[11px] text-ink-subtle mt-2">
                What each spec presses in at least half its goes, counted from {{ number_format($playbook['burst']['rounds']) }} measured arena rounds. Times are the longest wait; talents often bring them back sooner.
            </p>
        </div>

        {{-- Control for the kill target: where each spell lands in real games first, then the
             kit's stuns and silences the healer lock does not use (CompPlaybookService::killTargetControl). --}}
        <div class="linear-card p-5">
            <p class="text-[11px] uppercase tracking-wide text-gold font-semibold">Your go, part 3</p>
            <h3 class="text-[16px] text-ink font-semibold mt-0.5">On the player you are killing</h3>
            @if ($playbook['keep'])
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    @foreach ($playbook['keep'] as $k)
                        {!! $chip($k['spell'], $k['mi'], $k['split'] ? 'both ways' : null) !!}
                    @endforeach
                </div>
                <p class="text-[12px] text-ink-muted mt-2">Stuns and silences hold through damage, so they keep your target still while you hit them. Land one as your cooldowns go out.</p>
                @if (collect($playbook['keep'])->contains('split', true))
                    <p class="text-[12px] text-ink-subtle mt-1">
                        <span class="text-ink-muted">Both ways:</span> players are split on this one. Some open on their healer with it, some keep it for the kill. Pick one plan with your team, and do not spend it twice.
                    </p>
                @endif
            @else
                <p class="text-[12px] text-ink-muted mt-2">None: in real games this team's control goes on their healer.</p>
            @endif
        </div>

        {{-- Their go. --}}
        <div class="linear-card p-5">
            <p class="text-[11px] uppercase tracking-wide text-gold font-semibold">Their go</p>
            <h3 class="text-[16px] text-ink font-semibold mt-0.5">When they go on you, one defensive at a time</h3>
            <div class="mt-3 space-y-3">
                @foreach ($playbook['defensives'] as $mi => $entries)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[12px] font-medium w-44 flex-shrink-0" style="color: {{ $colorOf($mi) }}">{{ $who($mi) }}</span>
                        @forelse ($entries as $e)
                            {!! $chip($e['spell'], $mi, $secs($e['cooldown']['seconds'] ?? null)) !!}
                        @empty
                            <span class="text-[12px] text-ink-subtle">None listed.</span>
                        @endforelse
                    </div>
                @endforeach
            </div>
            <p class="text-[12px] text-ink-muted mt-4">
                Press one, and keep the rest for their next go. Everyone also has Gladiator's Medallion: it breaks crowd control, so keep it for when being stuck would get you or your healer killed.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="linear-card p-5">
                <h3 class="text-[15px] text-ink font-semibold">Kicks</h3>
                @if ($playbook['kicks'])
                    <div class="flex flex-wrap gap-2 mt-3">
                        @foreach ($playbook['kicks'] as $x)
                            {!! $chip($x['spell'], $x['mi'], $secs($x['cooldown'])) !!}
                        @endforeach
                    </div>
                    <p class="text-[12px] text-ink-muted mt-2">Kick their healer's heals during your go, and the crowd control they cast on your healer. Instant control cannot be kicked.</p>
                @else
                    <p class="text-[12px] text-ink-muted mt-2">Nobody on this team has a kick.</p>
                @endif
            </div>
            <div class="linear-card p-5">
                <h3 class="text-[15px] text-ink font-semibold">Peels</h3>
                @if ($playbook['peels'])
                    <div class="flex flex-wrap gap-2 mt-3">
                        @foreach ($playbook['peels'] as $x)
                            {!! $chip($x['spell'], $x['mi']) !!}
                        @endforeach
                    </div>
                    <p class="text-[12px] text-ink-muted mt-2">Put these on whoever is hitting your teammate, to buy time without spending a defensive.</p>
                @else
                    <p class="text-[12px] text-ink-muted mt-2">This team has no peels in the data yet.</p>
                @endif
            </div>
        </div>
    @endif
</div>
