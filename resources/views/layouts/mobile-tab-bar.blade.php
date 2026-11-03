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

<div class="sm:hidden">

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
            <a href="{{ route('home') }}" wire:navigate class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('home')) }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Home
            </a>
        @endauth

        <a href="{{ route('wow-comps') }}" wire:navigate class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('wow-comps')) }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Comps
        </a>

        {{-- The middle, gold: Your Games, what the product is (2026-11-03; it was the guide builder,
             which an audit found left a phone with no way to its own games). A guest lands on the
             sample game (CoachController::index). --}}
        <a href="{{ route('coach') }}"
           class="flex flex-col items-center justify-center gap-1 {{ request()->routeIs('coach', 'wow.sample') ? 'text-gold' : 'text-gold/90' }}">
            <span class="w-9 h-9 -mt-1 rounded-full bg-gold-gradient text-surface-0 flex items-center justify-center shadow-gold-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </span>
            Your Games
        </a>

        <a href="{{ route('guides.browse') }}" wire:navigate class="flex flex-col items-center justify-center gap-1 {{ $tab(request()->routeIs('guides.browse') || request()->routeIs('guides.show')) }}">
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
