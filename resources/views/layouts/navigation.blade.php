<aside class="fixed sm:relative inset-y-0 left-0 z-30
              flex flex-col w-56 h-screen shrink-0
              bg-surface-0 border-r border-line
              transition-transform duration-200 ease-in-out sm:translate-x-0"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <!-- App name -->
    <div class="flex items-center px-3 h-11 border-b border-gold/20 shrink-0">
        <a href="{{ auth()->check() ? route_with_context('dashboard') : route('modules.index') }}"
           class="flex items-center gap-2 hover:opacity-90 transition-opacity">
            <svg class="w-6 h-6 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
            </svg>
            <span class="text-[13px] font-semibold tracking-tight">
                <span class="text-ink">Mind</span><span class="text-gold">Collector</span>
            </span>
        </a>
    </div>

    <!-- Scrollable nav body -->
    <div class="flex-1 overflow-y-auto py-2 px-2 space-y-0.5">

        @auth
        <a href="{{ route_with_context('dashboard') }}"
           class="sidebar-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            Home
        </a>

        <a href="{{ route_with_context('collection.index') }}"
           class="sidebar-item {{ request()->routeIs('collection.index') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            Progress
        </a>
        @endauth

        <a href="{{ route_with_context('modules.index') }}"
           class="sidebar-item {{ request()->routeIs('modules.*') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            Discover
        </a>

        @can('admin')
        <!-- Creator -->
        <div x-data="{ creatorOpen: $persist(true).as('nav_creator_open') }" class="pt-1">
            <button @click="creatorOpen = !creatorOpen"
                    class="sidebar-item w-full justify-between">
                <div class="flex items-center gap-2.5">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span>Creator</span>
                </div>
                <svg :class="creatorOpen ? 'rotate-90' : ''"
                     class="w-3 h-3 text-ink-subtle transition-transform duration-150 shrink-0"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div x-show="creatorOpen"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="pl-3 mt-0.5 space-y-0.5">
                <a href="{{ route('modules.create') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('modules.create') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Create Module
                </a>
                <a href="{{ route('modules.manage') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('modules.manage') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Edit Modules
                </a>

                <div class="px-2.5 pt-2 pb-0.5">
                    <p class="text-[10px] font-medium text-ink-subtle uppercase tracking-widest">Content</p>
                </div>

                <a href="{{ route('admin.content') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.content') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Content Manager
                </a>
                <a href="{{ route('admin.api-usage') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.api-usage') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    API Usage
                </a>
                <a href="{{ route('admin.weak-areas') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.weak-areas') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Weak Areas
                </a>
                <a href="{{ route('admin.diagnostic-stats') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.diagnostic-stats') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Diagnostic Stats
                </a>
                <a href="{{ route('admin.page-usage') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.page-usage') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Page Usage
                </a>
                <a href="{{ route('jobs.dashboard') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('jobs.dashboard') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Job Queue
                </a>
                <a href="{{ route('admin.logs') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('admin.logs') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Log Viewer
                </a>
            </div>
        </div>
        @endcan

    </div>

    <!-- Feedback + Discord + Support -->
    <div class="shrink-0 px-2 pb-1 space-y-0.5">
        <a href="{{ route('feedback.create') }}"
           class="sidebar-item text-[12px] text-ink-subtle {{ request()->routeIs('feedback.*') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
            </svg>
            Feedback
        </a>
        <a href="https://discord.gg/Bk7wEvPRt"
           target="_blank"
           rel="noopener noreferrer"
           class="sidebar-item text-[12px] text-ink-subtle">
            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
            </svg>
            Join Discord
        </a>
        @auth
        <button id="nav-support-btn"
                class="sidebar-item w-full text-[12px] text-ink-subtle">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
            Support Development
        </button>
        @endauth
    </div>

    <!-- Footer: credits + user (auth) or sign-in prompt (guest) -->
    <div class="shrink-0 border-t border-line">
        @auth
        <!-- Credits row -->
        <div class="flex items-center justify-between px-3 py-2">
            <div class="flex items-center gap-1.5 text-[11px] text-ink-subtle">
                <svg class="w-3 h-3 text-gold shrink-0" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                Credits <span class="text-ink-muted font-medium">{{ $nav_ai_credits }}</span>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] text-ink-subtle">
                <svg class="w-3 h-3 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                XP <span class="text-ink-muted font-medium">{{ $nav_learned_credits }}</span>
            </div>
        </div>

        <!-- User menu -->
        <div x-data="{ open: false }" class="relative px-2 pb-2">
            <button @click="open = !open" class="sidebar-item w-full justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-5 h-5 rounded-full bg-gold/10 flex items-center justify-center text-[10px] font-semibold text-gold shrink-0">
                        {{ strtoupper(substr(Auth::user()?->name ?? 'U', 0, 1)) }}
                    </div>
                    <span class="truncate">{{ Auth::user()?->name }}</span>
                </div>
                <svg class="w-3 h-3 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
                </svg>
            </button>

            <div x-show="open"
                 @click.away="open = false"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-1"
                 class="absolute bottom-full left-2 right-2 mb-1 bg-surface-2 border border-line rounded-lg shadow-xl overflow-hidden"
                 style="display:none">
                <a href="{{ route('profile.edit') }}"
                   class="flex items-center gap-2 px-3 py-2 text-[12px] text-ink-muted hover:text-ink hover:bg-surface-3 transition-colors">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Profile
                </a>
                <div class="border-t border-line">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex items-center gap-2 w-full px-3 py-2 text-[12px] text-ink-muted hover:text-ink hover:bg-surface-3 transition-colors">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @else
        <!-- Guest: sign-in / register prompt -->
        <div class="px-2 py-2 space-y-1.5">
            <a href="{{ route('register') }}"
               class="flex items-center justify-center w-full px-3 py-1.5 rounded-md
                      text-[12px] font-semibold text-surface-0 bg-gold-gradient
                      hover:shadow-gold-sm transition-all duration-200">
                Sign up free
            </a>
            <a href="{{ route('login') }}"
               class="sidebar-item w-full justify-center text-[12px] text-ink-muted">
                Sign in
            </a>
        </div>
        @endauth
    </div>
</aside>

@auth
<script src="https://js.stripe.com/v3/"></script>
<script>
    document.getElementById('nav-support-btn').addEventListener('click', async () => {
        const stripe = Stripe("{{ config('services.stripe.key') }}");
        const res = await fetch("{{ route('checkout.session') }}", {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        });
        const data = await res.json();
        await stripe.redirectToCheckout({ sessionId: data.id });
    });
</script>
@endauth
