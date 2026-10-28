{{-- /wow/videos/{id} (VideoController::show): one game on video, with its timeline in a frame under the
     heading, as Your games shows it. $owner adds who can see it and the library switch. --}}
@php
    $g = $v->game ?? [];
    $title = $g['title'] ?? $v->title ?? 'Arena game';
    $povSpec = collect($g['us'] ?? [])->firstWhere('pov', true)['spec'] ?? null;
@endphp
<x-app-layout :title="$title.' | MindCollector'"
              :description="'A WoW arena game on video with its timeline: who fell and why, and every player\'s cooldowns as it plays.'.($povSpec ? ' Recorded by the '.$povSpec.'.' : '')"
              :og-image="'https://i.ytimg.com/vi/'.$v->video_id.'/hqdefault.jpg'">
<style>
    .video-frame { width: 100%; height: calc(100vh - 120px); min-height: 760px; border: 0; border-radius: 8px; background: #121212; }
    @media (max-width: 640px) { .video-frame { height: 85vh; min-height: 560px; } }
</style>

<div class="min-h-full py-4 px-4 lg:px-6">
    <div class="mx-auto space-y-3" style="max-width: 1500px" x-data="{ copied: false }">
        <div class="page-hero !py-5 flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1 min-w-0 space-y-2">
                <p class="page-eyebrow"><a href="{{ route('videos') }}" class="hover:text-gold-light">Videos</a>{{ $povSpec ? ' · recorded by the '.$povSpec : '' }}</p>
                <h1 class="text-[22px] lg:text-[26px] font-display text-ink leading-tight">{{ $title }}</h1>
                @include('videos.partials.teams', ['g' => $g])
                <p class="text-[13px] text-ink-muted">@include('videos.partials.meta', ['v' => $v])</p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
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
            <div class="linear-card p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1">
                    <p class="text-[13px] text-ink"><span class="font-bold">Your video.</span> {{ $v->shareLine() }}</p>
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

        <iframe class="video-frame" src="{{ route('videos.timeline', $v->video_id) }}" title="The game with its timeline" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    </div>
</div>
</x-app-layout>
