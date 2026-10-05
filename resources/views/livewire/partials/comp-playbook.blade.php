{{-- "How to play it": WowComps' plain guide for a player new to arena (CompPlaybookService).
     Expects $comp, $playbook (null until all three slots are filled), $drBadge, $openSpell, and
     optionally $part: 'plan' (the "How to play it" tab: the team's plan only, so it starts at the
     top), 'basics' (the Basics tab: the eight lines), or both when absent (nothing picked yet).
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
    {{-- The basics. Every line here is from the arena model (arena-structure.md) or a confirmed
         game fact (DR: full, half, then immune, reset after 20s; dr-categories-reference.md). --}}
    <div class="linear-card p-5" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between text-left">
            <span>
                <span class="text-[11px] uppercase tracking-wide text-gold font-semibold">New to arena?</span>
                <span class="block text-[15px] text-ink font-semibold mt-0.5">How a game is won, in eight lines</span>
            </span>
            <svg class="w-4 h-4 text-ink-subtle transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <ol x-show="open" x-cloak class="mt-4 space-y-2.5 text-[13px] text-ink-muted list-none">
            @foreach ([
                ['A game is a series of goes.', 'Your team picks one enemy, locks their healer in crowd control, and presses its big damage buttons together. Then they try the same on you.'],
                ['Press your big buttons together.', 'Two cooldowns a few seconds apart give their healer time to heal through each one.'],
                ['Their healer first, then the damage.', 'Land the crowd control on their healer, and press your cooldowns while it holds.'],
                ['The same kind of control gets shorter.', 'The second of one kind on the same player lasts half as long; the third does nothing. Use a different kind, or wait 20 seconds.'],
                ['When they go on you, one defensive at a time.', 'Two pressed at once leave you nothing for their next go.'],
                ['If the target will not die, change something.', 'Swap to whoever has the fewest defensives left, or back off until your cooldowns are back.'],
                ['Kick what matters.', 'Their healer\'s heals during your go, and the crowd control they cast on your healer.'],
                ['Use line of sight.', 'A spell needs line of sight to its target. Behind a pillar you cannot be cast on, and your healer cannot heal you either.'],
            ] as $n => [$head, $body])
                <li class="flex gap-3">
                    <span class="text-gold font-semibold w-4 flex-shrink-0 text-right">{{ $n + 1 }}</span>
                    <span><span class="text-ink font-medium">{{ $head }}</span> {{ $body }}</span>
                </li>
            @endforeach
            <li class="pt-1 pl-7">
                <a href="{{ route('wow-basics') }}" wire:navigate class="text-gold hover:text-gold-light">Check you have them: the arena basics check &rarr;</a>
            </li>
        </ol>
    </div>

    @endif

    @if ($part === 'basics')
    @elseif (! $playbook)
        <div class="linear-card p-5 text-[13px] text-ink-muted">
            Pick a spec for all three slots to see how that team plays: who locks their healer, which buttons go together, and what to save.
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
