{{-- Phones only. The sidebar is off-canvas below `sm`, behind a small hamburger, so on a phone none
     of the site's destinations were visible at all — a player who signed in on mobile saw one page
     and no way to reach the rest. This bar keeps the four places people actually go one tap away,
     with the full sidebar behind "Menu" for everything else.

     z-10, under the sidebar's backdrop (z-20) and the sidebar itself (z-30), so opening the menu
     covers the bar instead of leaving half of it clickable beside the drawer. Modals (z-50) sit
     over it as they already sit over everything. --}}
@php
    $tab = fn (bool $active) => $active ? 'text-gold' : 'text-ink-subtle';
    $pending = auth()->check() ? auth()->user()->pendingFriendRequestCount() : 0;
@endphp

{{-- The sheet sits OUTSIDE the <nav>: backdrop-blur on the nav creates a containing block for
     fixed-position children, so a sheet inside it would be positioned against the bar rather than
     the screen. --}}
<div class="sm:hidden" x-data="{ buildOpen: false }">

    @auth
        {{-- The Build sheet: two ways to start a guide, plus the list of your own. POSTs, because
             starting a guide creates a row — see the guides.create route. --}}
        <div x-show="buildOpen" x-cloak x-on:click="buildOpen = false"
             class="fixed inset-0 bg-black/50 z-40"></div>
        <div x-show="buildOpen" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="fixed inset-x-3 bottom-20 z-50 linear-card p-3 space-y-2">
            <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle px-1">Start a guide</p>
            <form method="POST" action="{{ route('guides.create', 'comp') }}">
                @csrf
                <button type="submit" class="btn-primary w-full justify-center">3v3 / 2v2 guide</button>
            </form>
            <form method="POST" action="{{ route('guides.create', 'class') }}">
                @csrf
                <button type="submit" class="btn-secondary w-full justify-center">Class guide</button>
            </form>
            <a href="{{ route('guides.index') }}" wire:navigate class="btn-ghost w-full justify-center">My guides</a>
        </div>
    @endauth

<nav class="fixed bottom-0 inset-x-0 z-10 border-t border-line bg-surface-0/95 backdrop-blur"
     style="padding-bottom: env(safe-area-inset-bottom)"
     aria-label="Main">
    <div class="grid grid-cols-5 h-16 text-[10.5px]">
        @auth
            <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('dashboard')) }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Home
            </a>
        @else
            <a href="{{ route('pvp-guides') }}" class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('pvp-guides')) }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Classes
            </a>
        @endauth

        <a href="{{ route('wow-comps') }}" class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('wow-comps')) }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Comps
        </a>

        @auth
            {{-- The middle, gold, and a button rather than a link: building a guide is the thing
                 this site most wants a player to do. --}}
            <button type="button" x-on:click="buildOpen = !buildOpen"
                    class="flex flex-col items-center justify-center gap-1 text-gold">
                <span class="w-9 h-9 -mt-1 rounded-full bg-gold-gradient text-surface-0 flex items-center justify-center shadow-gold-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                </span>
                Build
            </button>
        @else
            <a href="{{ route('register') }}" class="flex flex-col items-center justify-center gap-1 text-gold">
                <span class="w-9 h-9 -mt-1 rounded-full bg-gold-gradient text-surface-0 flex items-center justify-center shadow-gold-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                </span>
                Sign up
            </a>
        @endauth

        <a href="{{ route('guides.browse') }}" class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('guides.browse') || request()->routeIs('guides.show')) }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Guides
        </a>

        <button type="button" x-on:click="sidebarOpen = true"
                class="relative flex flex-col items-center justify-center gap-1 text-ink-subtle">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            Menu
            {{-- A friend request waiting is the one thing worth pulling someone into the menu for. --}}
            @if ($pending > 0)
                <span class="absolute top-2 right-[calc(50%-18px)] min-w-4 h-4 px-1 rounded-full bg-gold text-surface-0 text-[9.5px] font-semibold flex items-center justify-center tabular-nums">{{ $pending }}</span>
            @endif
        </button>
    </div>
</nav>
</div>
