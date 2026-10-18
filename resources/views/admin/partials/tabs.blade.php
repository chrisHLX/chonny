{{-- Every admin page in one strip across the top (2026-10-19), shown by layouts.app on any admin
     route. Replaces the "Creator" group at the bottom of the sidebar, which needed a scroll to reach. --}}
@php
    $adminTabs = [
        ['admin.overview', 'Overview'],
        ['admin.page-usage', 'Page usage'],
        ['admin.search-pages', 'Search pages'],
        ['admin.matches', 'Matches'],
        ['admin.diagnostic-stats', 'Diagnostic stats'],
        ['admin.api-usage', 'API usage'],
        ['admin.weak-areas', 'Weak areas'],
        ['admin.talent-builds', 'Talent builds'],
        ['admin.content', 'Content'],
        ['admin.game-data', 'Game data'],
        ['modules.manage', 'Modules'],
        ['jobs.dashboard', 'Job queue'],
        ['admin.logs', 'Logs'],
    ];
@endphp
<nav class="sticky top-0 z-20 bg-surface-0/95 backdrop-blur border-b border-line px-4 py-2">
    <div class="max-w-6xl mx-auto flex items-center gap-1.5 overflow-x-auto">
        <span class="text-[11px] font-bold uppercase tracking-[.12em] text-gold mr-2 shrink-0">Admin</span>
        @foreach ($adminTabs as [$route, $text])
            @if (Route::has($route))
                <a href="{{ route($route) }}" wire:navigate
                   class="shrink-0 px-3 py-1 rounded-full text-[12.5px] font-semibold transition-colors {{ request()->routeIs($route) ? 'bg-white text-black' : 'text-ink-muted hover:text-ink hover:bg-surface-2' }}">{{ $text }}</a>
            @endif
        @endforeach
    </div>
</nav>
