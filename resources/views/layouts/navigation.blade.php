<aside class="fixed sm:relative inset-y-0 left-0 z-30
              flex flex-col w-56 h-screen shrink-0
              bg-surface-0 border-r border-line
              transition-transform duration-200 ease-in-out sm:translate-x-0"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <!-- App name -->
    <div class="flex items-center gap-2.5 px-3 h-11 border-b border-line shrink-0">
        <div class="w-5 h-5 rounded bg-accent flex items-center justify-center shrink-0">
            <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
        </div>
        <a href="{{ route_with_context('dashboard') }}"
           class="text-[13px] font-semibold text-ink tracking-tight hover:text-ink transition-colors">
            Mindcollector
        </a>
    </div>

    <!-- Scrollable nav body -->
    <div class="flex-1 overflow-y-auto py-2 px-2 space-y-0.5">

        <a href="{{ route_with_context('dashboard') }}"
           class="sidebar-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            Dashboard
        </a>

        <a href="{{ route_with_context('collection.index') }}"
           class="sidebar-item {{ request()->routeIs('collection.index') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            Collection
        </a>

        <a href="{{ route_with_context('questions.quiz.index') }}"
           class="sidebar-item {{ request()->routeIs('questions.quiz.index') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Questions
        </a>

        <a href="{{ route_with_context('modules.index') }}"
           class="sidebar-item {{ request()->routeIs('modules.*') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            Modules
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
                <a href="{{ route('jobs.dashboard') }}"
                   class="sidebar-item text-[12px] {{ request()->routeIs('jobs.dashboard') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Job Queue
                </a>
            </div>
        </div>
        @endcan

        <!-- Categories -->
        <div x-data="{ catOpen: $persist(true).as('nav_cat_open') }" class="pt-1">
            <button @click="catOpen = !catOpen"
                    class="sidebar-item w-full justify-between">
                <div class="flex items-center gap-2.5">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                    <span>Categories</span>
                </div>
                <svg :class="catOpen ? 'rotate-90' : ''"
                     class="w-3 h-3 text-ink-subtle transition-transform duration-150 shrink-0"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div x-show="catOpen"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="pl-3 mt-0.5 space-y-0.5 max-h-48 overflow-y-auto">
                @foreach($nav_categories as $cat)
                    <a href="{{ route('modules.index', ['category_id' => $cat->id]) }}"
                       class="sidebar-item text-[12px] {{ request('category_id') == $cat->id ? 'active !text-accent' : '' }}">
                        <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        </div>

    </div>

    <!-- Footer: credits + user -->
    <div class="shrink-0 border-t border-line">
        <!-- Credits row -->
        <div class="flex items-center justify-between px-3 py-2">
            <div class="flex items-center gap-1.5 text-[11px] text-ink-subtle">
                <svg class="w-3 h-3 text-accent shrink-0" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                AI <span class="text-ink-muted font-medium">{{ $nav_ai_credits }}</span>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] text-ink-subtle">
                <svg class="w-3 h-3 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Learned <span class="text-ink-muted font-medium">{{ $nav_learned_credits }}</span>
            </div>
        </div>

        <!-- User menu -->
        <div x-data="{ open: false }" class="relative px-2 pb-2">
            <button @click="open = !open" class="sidebar-item w-full justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-5 h-5 rounded-full bg-accent/20 flex items-center justify-center text-[10px] font-semibold text-accent shrink-0">
                        {{ strtoupper(substr(Auth::user()->name ?? 'G', 0, 1)) }}
                    </div>
                    <span class="truncate">{{ Auth::user()->name ?? 'Guest' }}</span>
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
    </div>
</aside>
