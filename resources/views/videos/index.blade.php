{{-- /wow/videos (VideoController::index): the library of games on video, filtered by what the combat log
     knows exactly. Layout in its own <style>, as Your games does, so it never waits on an asset build. --}}
<x-app-layout title="WoW arena games on video, with their timelines | MindCollector"
              description="Watch real WoW arena games recorded with MindCollector, each with a timeline of who fell and why and every player's cooldowns as the video plays. Filter by spec, comp, bracket and MMR.">
<style>
    .vid-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
    .vid-card { display: block; border-radius: 8px; background: #181818; overflow: hidden; transition: background .15s; }
    .vid-card:hover { background: #232323; }
    .vid-thumb { position: relative; aspect-ratio: 16 / 9; background: #000; }
    .vid-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .vid-play { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; }
    .vid-card:hover .vid-play { opacity: 1; }
    .vid-play span { width: 48px; height: 48px; border-radius: 9999px; background: #C8952C; color: #000; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .vid-filter { background: #232323; color: #FFFFFF; border: 0; border-radius: 9999px; padding: 6px 30px 6px 14px; font-size: 13px; font-weight: 600; }
    .vid-filter.on { background: #FFFFFF; color: #000000; }
</style>

@php
    $labels = ['bracket' => 'Any bracket', 'spec' => 'Any spec in the game', 'pov' => 'Recorded by any spec', 'vs' => 'Against any class'];
@endphp

<div class="min-h-full py-4 px-4 lg:px-6">
    <div class="mx-auto space-y-4" style="max-width: 1500px">
        <div class="page-hero !py-6 flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1 min-w-0">
                <p class="page-eyebrow">Arena games on video</p>
                <h1 class="page-title">Videos</h1>
                <p class="text-[14px] text-ink-muted mt-3 max-w-3xl">
                    Real games recorded with the MindCollector app. Each one plays with its timeline underneath: who fell
                    and why, and every player's cooldowns as the video runs. The specs in every game come from its combat
                    log, so you can find exactly the matchup you want to watch.
                </p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                <a href="{{ route('app') }}" wire:navigate class="btn-primary">Record yours with the app</a>
            </div>
        </div>

        <form method="GET" action="{{ route('videos') }}" class="flex flex-wrap gap-2 items-center" x-data @change="$el.submit()">
            @foreach ($labels as $key => $any)
                @if (count($options[$key]) > 0)
                    <select name="{{ $key }}" class="vid-filter {{ isset($filters[$key]) ? 'on' : '' }}" aria-label="{{ $any }}">
                        <option value="">{{ $any }}</option>
                        @foreach ($options[$key] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters[$key] ?? null) === (string) $value)>{{ $key === 'pov' ? 'Recorded by '.$label : ($key === 'vs' ? 'Against '.$label : $label) }}</option>
                        @endforeach
                    </select>
                @endif
            @endforeach
            <select name="result" class="vid-filter {{ isset($filters['result']) ? 'on' : '' }}" aria-label="Result">
                <option value="">Won or lost</option>
                <option value="won" @selected(($filters['result'] ?? null) === 'won')>Won</option>
                <option value="lost" @selected(($filters['result'] ?? null) === 'lost')>Lost</option>
            </select>
            <select name="mmr" class="vid-filter {{ isset($filters['mmr']) ? 'on' : '' }}" aria-label="MMR">
                <option value="">Any MMR</option>
                @foreach ([1800, 2100, 2400, 2700] as $m)
                    <option value="{{ $m }}" @selected(($filters['mmr'] ?? null) === (string) $m)>{{ number_format($m) }}+ MMR</option>
                @endforeach
            </select>
            <select name="sort" class="vid-filter" aria-label="Order">
                <option value="">Newest first</option>
                <option value="views" @selected(($filters['sort'] ?? null) === 'views')>Most watched</option>
            </select>
            @if (array_diff_key($filters, ['sort' => true]))
                <a href="{{ route('videos') }}" class="text-[13px] text-gold hover:text-gold-light px-2">Clear</a>
            @endif
            <noscript><button type="submit" class="btn-secondary text-[13px]">Show</button></noscript>
        </form>

        @if ($videos->isEmpty())
            <div class="linear-card p-6">
                <p class="text-[16px] text-ink font-bold">{{ array_diff_key($filters, ['sort' => true]) ? 'No videos match these filters' : 'No videos here yet' }}</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    Games get here when a player uploads one to YouTube from the MindCollector app and makes it public, or
                    adds it on the Videos tab of Your games.
                </p>
            </div>
        @else
            <p class="text-[13px] text-ink-subtle">{{ $total }} {{ $total === 1 ? 'game' : 'games' }}</p>
            <div class="vid-grid">
                @foreach ($videos as $v)
                    <a href="{{ route('videos.show', $v->video_id) }}" class="vid-card">
                        <div class="vid-thumb">
                            <img src="https://i.ytimg.com/vi/{{ $v->video_id }}/mqdefault.jpg" alt="" loading="lazy">
                            <div class="vid-play"><span>&#9654;</span></div>
                        </div>
                        <div class="p-3 space-y-2">
                            <p class="text-[14px] text-ink font-bold leading-snug">{{ $v->game['title'] ?? $v->title ?? 'Arena game' }}</p>
                            @include('videos.partials.teams', ['g' => $v->game, 'size' => 'w-6 h-6'])
                            <p class="text-[12.5px] text-ink-muted">@include('videos.partials.meta', ['v' => $v])</p>
                        </div>
                    </a>
                @endforeach
            </div>
            @if ($pages > 1)
                <div class="flex gap-2 justify-center">
                    @for ($i = 1; $i <= $pages; $i++)
                        <a href="{{ route('videos', $filters + ['page' => $i]) }}" class="{{ $i === $page ? 'text-black bg-white' : 'text-ink' }} px-3 py-1 rounded-full text-[13px] font-semibold" style="{{ $i === $page ? '' : 'background: rgba(255,255,255,.07)' }}">{{ $i }}</a>
                    @endfor
                </div>
            @endif
        @endif
    </div>
</div>
</x-app-layout>
