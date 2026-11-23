{{-- Phones only. The sidebar is off-canvas below `sm`, behind a small hamburger, so on a phone none
     of the site's destinations were visible at all — a player who signed in on mobile saw one page
     and no way to reach the rest. This bar keeps the full sidebar behind "Menu", one tap away.

     Menu, then the games (2026-11-24, Chriso): the sidebar's game pills were too small to tap on a
     phone, so the games moved down here as full-size tabs, the open one lit in its colour. Comps,
     Guides and Your Games left the bar for the game's own menu in the sidebar, where every one of
     them already was. Same tabs as the sidebar's switcher (PlatformController::switcher).

     z-10, under the sidebar's backdrop (z-20) and the sidebar itself (z-30), so opening the menu
     covers the bar instead of leaving half of it clickable beside the drawer. Modals (z-50) sit
     over it as they already sit over everything. --}}
@php
    $barGames = \App\Http\Controllers\PlatformController::switcher();
    $barOn = \App\Http\Controllers\PlatformController::navGame();
    $pending = auth()->check() ? auth()->user()->pendingFriendRequestCount() : 0;
@endphp

<div class="sm:hidden">

<nav class="fixed bottom-0 inset-x-0 z-10 border-t border-line bg-surface-0/95 backdrop-blur"
     style="padding-bottom: env(safe-area-inset-bottom)"
     aria-label="Main">
    <div class="grid h-16 text-[11px]" style="grid-template-columns: repeat({{ 1 + count($barGames) }}, minmax(0, 1fr))">
        <button type="button" x-on:click="sidebarOpen = true"
                class="relative flex flex-col items-center justify-center gap-1 text-ink-subtle active:text-ink">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            Menu
            {{-- A friend request waiting is the one thing worth pulling someone into the menu for. --}}
            @if ($pending > 0)
                <span class="absolute top-2 right-[calc(50%-20px)] min-w-4 h-4 px-1 rounded-full bg-gold text-surface-0 text-[9.5px] font-semibold flex items-center justify-center tabular-nums">{{ $pending }}</span>
            @endif
        </button>

        @foreach ($barGames as $gSlug => $g)
            @php $gOn = $barOn === $gSlug; @endphp
            <a href="{{ route($g['route']) }}" wire:navigate
               @if ($gOn) aria-current="true" @endif
               class="relative flex flex-col items-center justify-center gap-1 font-semibold {{ $gOn ? 'text-ink' : 'text-ink-subtle active:text-ink' }}">
                @if ($gOn)
                    <span class="absolute top-0 inset-x-5 h-0.5 rounded-full" style="background: {{ $g['color'] }}"></span>
                @endif
                <x-mc-icon :name="$g['icon']" class="w-6 h-6 shrink-0" style="color: {{ $gOn || $g['status'] === 'live' ? $g['color'] : '#6A6A6A' }}" />
                {{ $g['short'] }}
            </a>
        @endforeach
    </div>
</nav>
</div>
