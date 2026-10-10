{{-- "Your games" (/wow/coach): every page about the player's own games in one place. See CoachController.
     Layout in its own <style>, not new Tailwind classes, so it never waits on an asset build. --}}
<x-app-layout>
<style>
    /* Spotify's library and player (2026-10-11): rows on the left, the page on the right. */
    .coach { display: grid; grid-template-columns: 340px 1fr; gap: 8px; align-items: start; }
    .coach-list { max-height: calc(100vh - 250px); overflow-y: auto; padding: 8px; border-radius: 8px; background: #181818; }
    .coach-frame { width: 100%; height: calc(100vh - 210px); min-height: 520px; border: 0; border-radius: 8px; background: #121212; }
    .coach-item { display: block; width: 100%; text-align: left; padding: 8px 10px; border-radius: 6px; font-size: 14px; font-weight: 600; color: #FFFFFF; }
    .coach-item:hover { background: #232323; }
    .coach-item.on { background: #2A2A2A; box-shadow: inset 3px 0 0 #C8952C; }
    .coach-item .sub { display: block; font-size: 12.5px; font-weight: 400; color: #B3B3B3; margin-top: 2px; }
    .coach-tab { padding: 6px 14px; border-radius: 9999px; font-size: 13px; font-weight: 600; color: #FFFFFF; background: rgba(255,255,255,.07); }
    .coach-tab:hover { background: rgba(255,255,255,.12); }
    .coach-tab.on { color: #000000; background: #FFFFFF; }
    .won { color: #4ade80; } .lost { color: #f87171; }
    @media (max-width: 900px) {
        .coach { grid-template-columns: 1fr; }
        .coach-list { max-height: 260px; }
        .coach-frame { height: 80vh; }
    }
</style>

@php
    // The app's pages need something built; until then the Upload tab is where a player starts.
    $pageTabs = ['games' => 'Games', 'improve' => 'Improve', 'comps' => 'Comps', 'shuffle' => 'Shuffle', 'classes' => 'Classes'];
    $startTab = $newKey || ! $built ? 'upload' : 'games';
@endphp

<div class="min-h-full py-4 px-4 lg:px-6">
    <div class="mx-auto space-y-4" style="max-width: 1500px" x-data="{ tab: @js($startTab), file: @js($games[0]['file'] ?? null) }">
        <div class="page-hero !py-6">
            <p class="page-eyebrow">Only you can see this page</p>
            <h1 class="page-title">Your games</h1>
            <p class="text-[14px] text-ink-muted mt-3 max-w-3xl">Every game you've uploaded, what to work on next, the comps you've played against and the best player of each spec you've met.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($pageTabs + ['mirror' => 'Same spec', 'upload' => 'Upload'] as $key => $label)
                <button type="button" class="coach-tab" :class="tab === '{{ $key }}' && 'on'" @click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>

        {{-- The app's pages: a list on the left, the page in a frame on the right. --}}
        <div x-show="['games', 'improve', 'comps', 'shuffle', 'classes'].includes(tab)" x-cloak>
            @if (! $built)
                <div class="linear-card p-6">
                    <p class="text-[16px] text-ink font-bold">No games here yet</p>
                    <p class="text-[14px] text-ink-muted mt-1">
                        Upload a combat log on the <button type="button" class="text-gold hover:underline" @click="tab = 'upload'">Upload</button> tab,
                        or let the MindCollector app send your games as you play.
                    </p>
                </div>
            @else
                <div class="coach">
                    <div class="linear-card p-3 coach-list">
                        <div x-show="tab === 'games'">
                            @foreach ($games as $g)
                                <button type="button" class="coach-item" :class="file === '{{ $g['file'] }}' && 'on'" @click="file = '{{ $g['file'] }}'">
                                    {{ \Illuminate\Support\Carbon::parse($g['playedAt'])->format('D j M, H:i') }}
                                    @if (isset($g['record']))
                                        &middot; <span class="{{ $g['record'][0] > $g['record'][1] ? 'won' : 'lost' }}">{{ count($g['against'] ?? []) ? ($g['record'][0] ? 'Won' : 'Lost') : $g['record'][0].'-'.$g['record'][1] }}</span>
                                    @endif
                                    <span class="sub">{{ $g['you'] ?? '' }} &middot; {{ str_replace('Rated ', '', $g['bracket']) }}{{ ! empty($g['against']) ? ' vs '.implode(', ', $g['against']) : '' }}</span>
                                </button>
                            @endforeach
                        </div>
                        <div x-show="tab === 'improve'" x-cloak>
                            @foreach ($characters as $ch)
                                <button type="button" class="coach-item" :class="file === '{{ $ch['file'] }}' && 'on'" @click="file = '{{ $ch['file'] }}'">
                                    {{ $ch['name'] }} <span class="sub">{{ $ch['spec'] }} &middot; {{ $ch['games'] }} games</span>
                                </button>
                            @endforeach
                        </div>
                        @foreach (['comps' => $comps, 'shuffle' => $shuffle] as $key => $list)
                            <div x-show="tab === '{{ $key }}'" x-cloak>
                                @forelse ($list as $cp)
                                    <button type="button" class="coach-item" :class="file === '{{ $cp['file'] }}' && 'on'" @click="file = '{{ $cp['file'] }}'">
                                        {{ $cp['nick'] ? $cp['nick'].' · ' : '' }}{{ preg_replace('/^[^:]+:\s*/', '', $cp['name']) }}
                                        <span class="sub">{{ $cp['games'] }} game{{ $cp['games'] === 1 ? '' : 's' }} &middot; {{ $cp['won'] }}-{{ $cp['lost'] }}</span>
                                    </button>
                                @empty
                                    <p class="text-[12px] text-ink-subtle p-2">None yet.</p>
                                @endforelse
                            </div>
                        @endforeach
                        {{-- The strongest player of each spec you met, by class. --}}
                        <div x-show="tab === 'classes'" x-cloak>
                            @forelse ($classes as $class => $players)
                                <p class="text-[11px] uppercase tracking-wider text-ink-subtle px-2 pt-2">{{ $class }}</p>
                                @foreach ($players as $pl)
                                    <button type="button" class="coach-item" :class="file === '{{ $pl['file'] }}' && 'on'" @click="file = '{{ $pl['file'] }}'">
                                        <span style="color: {{ $pl['color'] }}">{{ $pl['name'] }}</span>
                                        <span class="sub">{{ $pl['spec'] }} &middot; {{ $pl['why'] }} &middot; {{ $pl['games'] }} round{{ $pl['games'] === 1 ? '' : 's' }}</span>
                                    </button>
                                @endforeach
                            @empty
                                <p class="text-[12px] text-ink-subtle p-2">None yet.</p>
                            @endforelse
                        </div>
                    </div>
                    <iframe class="coach-frame" :src="file ? '/wow/coach/page/' + file : 'about:blank'" title="The selected page"></iframe>
                </div>
            @endif
        </div>

        {{-- Same spec: Game Review's lobby reviews (Solo Shuffle, you against the other player of
             your spec), each on its own page. --}}
        <div x-show="tab === 'mirror'" x-cloak class="linear-card p-4">
            <p class="text-[13px] text-ink-muted mb-3">Your Solo Shuffle lobbies, round by round: what every player did, and how you compared with the other player of your spec on talents, gear and stats.</p>
            @forelse ($mirrors as $m)
                <a href="{{ route('game-review', ['id' => $m['id']]) }}" wire:navigate class="coach-item">
                    {{ $m['playedAt'] ? \Illuminate\Support\Carbon::parse($m['playedAt'])->format('D j M, H:i') : '' }}
                    &middot; <span class="{{ $m['record']['won'] > $m['record']['lost'] ? 'won' : 'lost' }}">{{ $m['record']['won'] }}-{{ $m['record']['lost'] }}</span>
                    <span class="sub">{{ $m['you'] }}{{ $m['youSpec'] ? ' · '.$m['youSpec'] : '' }} &middot; {{ str_replace('Rated ', '', $m['bracket'] ?? '') }}</span>
                </a>
            @empty
                <p class="text-[13px] text-ink-subtle">No Solo Shuffle lobbies yet. They'll show up once you upload a log with one in it.</p>
            @endforelse
        </div>

        {{-- Upload: in the browser (nothing to install), or from the desktop app with a key. --}}
        <div x-show="tab === 'upload'" x-cloak class="space-y-4">
            <livewire:games-upload/>

            @if ($newKey)
                <div class="linear-card p-4 border-gold-muted">
                    <p class="text-[14px] text-ink font-bold">Your app key</p>
                    <p class="text-[13px] text-ink-muted mt-1">Paste it into the MindCollector app under Settings. We only show it once, and making a new key stops this one working.</p>
                    <p class="mt-2 font-mono text-[13px] text-gold-light select-all break-all">{{ $newKey }}</p>
                </div>
            @endif

            <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1">
                    <p class="text-[14px] text-ink font-bold">Or let the app send them</p>
                    <p class="text-[13px] text-ink-muted">
                        @if ($hasKey)
                            You made a key {{ \Illuminate\Support\Carbon::parse($keyMadeAt)->diffForHumans() }}. Making a new one replaces it.
                        @else
                            Once it has your key, the MindCollector app sends each game when it ends.
                        @endif
                    </p>
                </div>
                <form method="POST" action="{{ route('coach.key') }}">
                    @csrf
                    <button type="submit" class="btn-secondary text-[13px]">{{ $hasKey ? 'Make a new key' : 'Make a key' }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
