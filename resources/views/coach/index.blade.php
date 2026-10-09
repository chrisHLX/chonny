{{-- "Your games" (/wow/coach): every page about the player's own games in one place. See CoachController.
     Layout in its own <style>, not new Tailwind classes, so it never waits on an asset build. --}}
<x-app-layout>
<style>
    .coach { display: grid; grid-template-columns: 320px 1fr; gap: 16px; align-items: start; }
    .coach-list { max-height: calc(100vh - 230px); overflow-y: auto; }
    .coach-frame { width: 100%; height: calc(100vh - 190px); min-height: 520px; border: 1px solid #1E1E26; border-radius: 8px; background: #111116; }
    .coach-item { display: block; width: 100%; text-align: left; padding: 7px 10px; border-radius: 6px; font-size: 13px; color: #F0F0F2; }
    .coach-item:hover { background: #18181E; }
    .coach-item.on { background: #1E150A; box-shadow: inset 2px 0 0 #C8952C; }
    .coach-item .sub { display: block; font-size: 11.5px; color: #8A8A9A; margin-top: 1px; }
    .coach-tab { padding: 6px 12px; border-radius: 6px; font-size: 13px; color: #8A8A9A; border: 1px solid transparent; }
    .coach-tab.on { color: #E8B84B; border-color: #6B4E1A; background: #1E150A; }
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

<div class="min-h-full py-6 px-4 lg:px-8">
    <div class="mx-auto space-y-4" style="max-width: 1500px" x-data="{ tab: @js($startTab), file: @js($games[0]['file'] ?? null) }">
        <div>
            <h1 class="font-display text-[20px] font-bold text-ink">Your games</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Each of your games, what to work on, every comp you have met, the strongest player of each spec you have played against, and same-spec reviews of your Solo Shuffles. Only you can see them.</p>
        </div>

        <div class="flex flex-wrap gap-1">
            @foreach ($pageTabs + ['mirror' => 'Same spec', 'upload' => 'Upload'] as $key => $label)
                <button type="button" class="coach-tab" :class="tab === '{{ $key }}' && 'on'" @click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>

        {{-- The app's pages: a list on the left, the page in a frame on the right. --}}
        <div x-show="['games', 'improve', 'comps', 'shuffle', 'classes'].includes(tab)" x-cloak>
            @if (! $built)
                <div class="linear-card p-5">
                    <p class="text-[14px] text-ink font-semibold">No games here yet</p>
                    <p class="text-[13px] text-ink-muted mt-1">
                        Upload your combat log on the <button type="button" class="text-gold hover:underline" @click="tab = 'upload'">Upload</button> tab,
                        or let the desktop app send your games. Each game, what to work on and your comps appear here.
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
            <p class="text-[13px] text-ink-muted mb-3">Your Solo Shuffle lobbies read round by round: every player's output, and where you and the other player of your spec differed in talents, gear and stats.</p>
            @forelse ($mirrors as $m)
                <a href="{{ route('game-review', ['id' => $m['id']]) }}" wire:navigate class="coach-item">
                    {{ $m['playedAt'] ? \Illuminate\Support\Carbon::parse($m['playedAt'])->format('D j M, H:i') : '' }}
                    &middot; <span class="{{ $m['record']['won'] > $m['record']['lost'] ? 'won' : 'lost' }}">{{ $m['record']['won'] }}-{{ $m['record']['lost'] }}</span>
                    <span class="sub">{{ $m['you'] }}{{ $m['youSpec'] ? ' · '.$m['youSpec'] : '' }} &middot; {{ str_replace('Rated ', '', $m['bracket'] ?? '') }}</span>
                </a>
            @empty
                <p class="text-[13px] text-ink-subtle">No Solo Shuffle lobbies yet. They appear here once you upload a log with one.</p>
            @endforelse
        </div>

        {{-- Upload: in the browser (nothing to install), or from the desktop app with a key. --}}
        <div x-show="tab === 'upload'" x-cloak class="space-y-4">
            <livewire:games-upload/>

            @if ($newKey)
                <div class="linear-card p-4 border-gold-muted">
                    <p class="text-[13px] text-ink font-semibold">Your desktop app key</p>
                    <p class="text-[12px] text-ink-muted mt-1">Paste it into MindCollector Logs, Settings, "Website key". It is shown this once; making a new one stops the old one working.</p>
                    <p class="mt-2 font-mono text-[13px] text-gold-light select-all break-all">{{ $newKey }}</p>
                </div>
            @endif

            <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1">
                    <p class="text-[13px] text-ink font-semibold">Or let the desktop app send them</p>
                    <p class="text-[12px] text-ink-muted">
                        @if ($hasKey)
                            You made a key {{ \Illuminate\Support\Carbon::parse($keyMadeAt)->diffForHumans() }}. A new one replaces it.
                        @else
                            MindCollector Logs sends each game after you play, once it has your key.
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
