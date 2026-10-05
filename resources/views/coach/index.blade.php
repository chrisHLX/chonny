{{-- "Your coach" (/wow/coach): the desktop app's pages on the website. See CoachController.
     Layout in its own <style>, not new Tailwind classes, so it never waits on an asset build. --}}
<x-app-layout>
<style>
    .coach { display: grid; grid-template-columns: 320px 1fr; gap: 16px; align-items: start; }
    .coach-list { max-height: calc(100vh - 210px); overflow-y: auto; }
    .coach-frame { width: 100%; height: calc(100vh - 170px); min-height: 520px; border: 1px solid #1E1E26; border-radius: 8px; background: #111116; }
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

<div class="min-h-full py-6 px-4 lg:px-8">
    <div class="mx-auto space-y-4" style="max-width: 1500px">
        <div>
            <h1 class="font-display text-[20px] font-bold text-ink">Your coach</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">The MindCollector Logs pages for your own games: each game, what to work on, and every comp you have met. Only you can see them.</p>
        </div>

        @if ($newKey)
            <div class="linear-card p-4 border-gold-muted">
                <p class="text-[13px] text-ink font-semibold">Your desktop app key</p>
                <p class="text-[12px] text-ink-muted mt-1">Paste it into MindCollector Logs, Settings, "Website key". It is shown this once; making a new one stops the old one working.</p>
                <p class="mt-2 font-mono text-[13px] text-gold-light select-all break-all">{{ $newKey }}</p>
            </div>
        @endif

        @if (! $built)
            <div class="linear-card p-5">
                <p class="text-[14px] text-ink font-semibold">No games here yet</p>
                <p class="text-[13px] text-ink-muted mt-1">The desktop app sends your games here after each sync once it has your key. Make a key below and paste it into the app's Settings.</p>
            </div>
        @else
            <div x-data="{ tab: 'games', file: @js($games[0]['file'] ?? null) }" class="coach">
                <div class="linear-card p-3">
                    <div class="flex flex-wrap gap-1 mb-3">
                        @foreach (['games' => 'Games', 'improve' => 'Improve', 'comps' => 'Comps', 'shuffle' => 'Shuffle'] as $key => $label)
                            <button type="button" class="coach-tab" :class="tab === '{{ $key }}' && 'on'" @click="tab = '{{ $key }}'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="coach-list">
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
                    </div>
                </div>
                <iframe class="coach-frame" :src="file ? '/wow/coach/page/' + file : 'about:blank'" title="The selected page"></iframe>
            </div>
        @endif

        <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <div class="flex-1">
                <p class="text-[13px] text-ink font-semibold">Desktop app key</p>
                <p class="text-[12px] text-ink-muted">
                    @if ($hasKey)
                        You made one {{ \Illuminate\Support\Carbon::parse($keyMadeAt)->diffForHumans() }}. A new one replaces it.
                    @else
                        The desktop app needs a key to send your games here.
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
</x-app-layout>
