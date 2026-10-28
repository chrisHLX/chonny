{{-- /wow/videos/{id} (VideoController::show): one game on video, with its timeline in a frame under a
     short heading. On a wide screen the page fits the window and the frame takes everything under the
     heading (2026-10-29: the old tall heading left too little room to watch and read at once).
     $owner adds who can see it and the library switch, folded away behind "Your video". --}}
@php
    $g = $v->game ?? [];
    $title = $g['title'] ?? $v->title ?? 'Arena game';
    $povSpec = collect($g['us'] ?? [])->firstWhere('pov', true)['spec'] ?? null;
@endphp
<x-app-layout :title="$title.' | MindCollector'"
              :description="'A WoW arena game on video with its timeline: who fell and why, and every player\'s cooldowns as it plays.'.($povSpec ? ' Recorded by the '.$povSpec.'.' : '')"
              :og-image="'https://i.ytimg.com/vi/'.$v->video_id.'/hqdefault.jpg'">
<style>
    .video-page { display: flex; flex-direction: column; }
    .video-frame { display: block; width: 100%; height: 85vh; min-height: 520px; border: 0; border-radius: 8px; background: #121212; }
    @media (min-width: 1024px) {
        .video-page { height: 100%; }
        .video-frame { flex: 1 1 auto; height: auto; }
    }
</style>

<div class="video-page px-3 lg:px-4 py-3 gap-2" x-data="{ copied: false, mine: {{ session('videos_status') ? 'true' : 'false' }} }">
    <div class="w-full mx-auto flex flex-col lg:flex-row lg:items-center gap-3 px-1" style="max-width: 1700px">
        <div class="flex-1 min-w-0">
            <p class="page-eyebrow"><a href="{{ route('videos') }}" class="hover:text-gold-light">Videos</a>{{ $povSpec ? ' · recorded by the '.$povSpec : '' }}</p>
            <h1 class="text-[17px] lg:text-[19px] font-display text-ink leading-tight mt-0.5 lg:truncate" title="{{ $title }}">{{ $title }}</h1>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
                @include('videos.partials.teams', ['g' => $g, 'size' => 'w-6 h-6'])
                <span class="text-[12.5px] text-ink-muted">@include('videos.partials.meta', ['v' => $v])</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            @if ($owner)
                <button type="button" class="btn-ghost text-[13px]" @click="mine = ! mine" :aria-expanded="mine">
                    Your video <span x-text="mine ? '▴' : '▾'">▾</span>
                </button>
            @endif
            @if ($v->watchable())
                <button type="button" class="btn-secondary text-[13px]"
                        @click="navigator.clipboard.writeText(@js(route('videos.show', $v->video_id))).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-show="! copied">Copy link</span><span x-show="copied" x-cloak>Copied</span>
                </button>
            @endif
            <a href="https://youtu.be/{{ $v->video_id }}" target="_blank" rel="noopener" class="btn-ghost text-[13px]">Watch on YouTube &#8599;</a>
        </div>
    </div>

    @if ($owner)
        <div class="w-full mx-auto linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3" style="max-width: 1700px" x-show="mine" x-cloak>
            <div class="flex-1">
                <p class="text-[13px] text-ink">{{ $v->shareLine() }}</p>
                <p class="text-[12.5px] text-ink-subtle mt-1">Everyone sees this page with specs in place of names, as in the video's title.</p>
                @if (session('videos_status'))<p class="text-[13px] text-emerald-300 mt-1">{{ session('videos_status') }}</p>@endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($v->watchable())
                    <form method="POST" action="{{ route('coach.videos.listed', $v->video_id) }}">
                        @csrf
                        <input type="hidden" name="listed" value="{{ $v->inLibrary() ? '0' : '1' }}">
                        <button type="submit" class="btn-secondary text-[13px]">{{ $v->inLibrary() ? 'Take it out of the library' : 'Put it in the library' }}</button>
                    </form>
                @endif
                <a href="https://studio.youtube.com/video/{{ $v->video_id }}/edit" target="_blank" rel="noopener" class="btn-ghost text-[13px]">Change it on YouTube &#8599;</a>
            </div>
        </div>
    @endif

    <iframe class="video-frame mx-auto" style="max-width: 1700px" src="{{ route('videos.timeline', $v->video_id) }}" title="The game with its timeline" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
</div>
</x-app-layout>
