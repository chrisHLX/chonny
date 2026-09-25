<aside class="fixed sm:relative inset-y-0 left-0 z-30
              flex flex-col w-56 h-screen shrink-0
              bg-surface-0 border-r border-line
              transition-transform duration-200 ease-in-out sm:translate-x-0"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <!-- App name -->
    <div class="flex items-center px-3 h-11 border-b border-gold/20 shrink-0">
        <a href="{{ auth()->check() ? route('dashboard') : url('/') }}" wire:navigate
           class="flex items-center gap-2 hover:opacity-90 transition-opacity">
            <svg class="w-6 h-6 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
            </svg>
            <span class="text-[13px] font-semibold tracking-tight">
                <span class="text-ink">Mind</span><span class="text-gold">Collector</span>
            </span>
            <span class="text-[9px] uppercase tracking-wide text-ink-subtle border border-line-strong rounded px-1 py-0.5 leading-none">Beta</span>
        </a>
    </div>

    <!-- Scrollable nav body -->
    <div class="flex-1 overflow-y-auto py-2 px-2 space-y-0.5">

        {{-- SIMPLIFIED, 2026-09-14. Every link in this sidebar says "this is something you should
             understand", so it carries only what the site is now about: game plans. Five short
             groups, in this order —
               Your space   the signed-in player's own pages, in one tinted card with their name on it
               Explore      the public site: guides and comps
               Social       friends and guilds
               Class data   the reference pages behind the plans
               Training     diagnostic and quizzes, collapsed until opened (open on its own pages)
             Feedback / Discord / Support moved to one small row at the bottom, and credits/XP into
             the account menu. History: 2026-09-13 moved the arena side above the quiz pages;
             2026-09-14 (earlier) split "Your space" from the public site. --}}
        @auth
            @php
                $navMe = auth()->user();
                $navPendingFriends = $navMe->pendingFriendRequestCount();
            @endphp
            <div class="rounded-lg border border-line-gold bg-gold-subtle/60 p-1 mb-2">
                <div class="flex items-center gap-2 px-2 pt-1.5 pb-2">
                    <div class="w-6 h-6 rounded-full bg-gold/15 border border-gold/30 flex items-center justify-center text-[11px] font-semibold text-gold shrink-0">
                        {{ strtoupper(substr($navMe->name ?? 'U', 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9.5px] font-semibold text-gold uppercase tracking-widest leading-none">Your space</p>
                        <p class="text-[12px] text-ink truncate leading-tight mt-1">
                            {{ $navMe->name }}@if ($navMe->username)<span class="text-ink-subtle"> &middot; &#64;{{ $navMe->username }}</span>@endif
                        </p>
                    </div>
                </div>

                <a href="{{ route('dashboard') }}" wire:navigate
                   class="sidebar-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Home
                </a>

                <a href="{{ route('guides.index') }}" wire:navigate
                   class="sidebar-item {{ request()->routeIs('guides.index') || request()->routeIs('guides.edit') ? 'active' : '' }}">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    My Guides
                </a>

                <a href="{{ route('characters.index') }}" wire:navigate
                   class="sidebar-item {{ request()->routeIs('characters.*') ? 'active' : '' }}">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    My Characters
                </a>

                <a href="{{ route('profile.edit') }}" wire:navigate
                   class="sidebar-item {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Profile &amp; settings
                </a>
            </div>
        @endauth

        {{-- Explore: the public site, identical for every visitor. --}}
        <p class="px-2.5 pt-3 pb-1 text-[10px] font-medium text-ink-subtle uppercase tracking-widest">Explore</p>

        {{-- A visitor's home is the public front page; a signed-in player's is in "Your space". --}}
        @guest
            <a href="{{ route('home') }}" wire:navigate
               class="sidebar-item {{ request()->routeIs('home') ? 'active' : '' }}">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Home
            </a>
        @endguest

        {{-- One "Guides" entry with Claude's nested under it, 2026-09-24. Explore had five
             top-level items and two of them ("Player Guides", "Claude's Comp Guides") were the
             same kind of thing, which read as two destinations rather than one with a variant.
             The indented child follows the same pattern "Class data" already uses below.

             NAV ONLY — the two listings stay separate pages. CLAUDE.md rule 30: machine-drafted
             guides are filtered OUT of Browse, GuideFeed and Home::exampleGuide() by
             humanAuthored(), and they carry their own byline. Nesting the link must not be read
             as licence to merge the lists. --}}
        <a href="{{ route('guides.browse') }}" wire:navigate
           class="sidebar-item {{ request()->routeIs('guides.browse') || request()->routeIs('guides.show') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            Guides
        </a>

        <a href="{{ route('guides.machine') }}" wire:navigate
           class="sidebar-item text-[12px] {{ request()->routeIs('guides.machine') ? 'active !text-accent' : '' }}">
            <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
            Claude
        </a>

        <a href="{{ route('wow-comps') }}" wire:navigate
           class="sidebar-item {{ request()->routeIs('wow-comps') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            3v3 Comps
        </a>

        {{-- Directly under 3v3 Comps: same picker, one step further on — two comps instead of
             one, and a read of the matchup rather than a listing of the kits. --}}
        <a href="{{ route('matchup-lab') }}" wire:navigate
           class="sidebar-item {{ request()->routeIs('matchup-lab') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 17l5-6 4 4 5-8 4 5"/>
            </svg>
            Matchup Lab
        </a>

        {{-- After the two forward-looking pages: this is the same matchup read backwards, off a
             game that actually happened. See App\Livewire\GameReview. --}}
        <a href="{{ route('game-review') }}" wire:navigate
           class="sidebar-item {{ request()->routeIs('game-review') ? 'active' : '' }}">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            Game Review
        </a>

        @auth
            <p class="px-2.5 pt-3 pb-1 text-[10px] font-medium text-ink-subtle uppercase tracking-widest">Social</p>

            <a href="{{ route('friends.index') }}" wire:navigate
               class="sidebar-item {{ request()->routeIs('friends.*') ? 'active' : '' }}">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
                Friends
                @if ($navPendingFriends > 0)
                    <span class="ml-auto badge-gold tabular-nums">{{ $navPendingFriends }}</span>
                @endif
            </a>

            <a href="{{ route('guilds.index') }}" wire:navigate
               class="sidebar-item {{ request()->routeIs('guilds.*') ? 'active' : '' }}">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/>
                </svg>
                Guilds
            </a>
        @endauth

        <p class="px-2.5 pt-3 pb-1 text-[10px] font-medium text-ink-subtle uppercase tracking-widest">Class data</p>

        {{-- One link replacing four (Class Kits / Burst Guides / Spell Counters / Spells),
             2026-09-07: all four answer questions about a single class/spec, so they are now
             tabs on one per-spec page. The four routes still exist and still render on their
             own for anything already bookmarked or linked; they are just no longer separate
             destinations in the nav. See App\Livewire\PvpGuides. --}}
        <a href="{{ route('pvp-guides') }}" wire:navigate
           class="sidebar-item text-[12px] {{ request()->routeIs('pvp-guides') || request()->routeIs('class-guide') || request()->routeIs('burst-guides') || request()->routeIs('claudes-counters') || request()->routeIs('spells.explore') ? 'active !text-accent' : '' }}">
            <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
            Class Guides
        </a>
        <a href="{{ route('top-damage-rotations') }}" wire:navigate
           class="sidebar-item text-[12px] {{ request()->routeIs('top-damage-rotations') ? 'active !text-accent' : '' }}">
            <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
            Top Burst Windows
        </a>
        <a href="{{ route('top-cc-chains') }}" wire:navigate
           class="sidebar-item text-[12px] {{ request()->routeIs('top-cc-chains') ? 'active !text-accent' : '' }}">
            <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
            Top 10 CC Chains
        </a>

        {{-- Training: class quizzes. Open by default since 2026-09-17 (new persist key), when the
             old module pages were hidden and this became the class quizzes' home. Always open while
             you are on one of its own pages. --}}
        @php $onTrainingPage = request()->routeIs('training') || request()->routeIs('modules.*') || request()->routeIs('collection.index') || request()->routeIs('wow-quiz*') || request()->routeIs('strategy') || request()->routeIs('brain'); @endphp
        <div x-data="{ open: $persist(true).as('nav_training_open_v2') }">
            <button type="button" @click="open = !open"
                    class="w-full flex items-center justify-between px-2.5 pt-3 pb-1 text-[10px] font-medium text-ink-subtle uppercase tracking-widest hover:text-ink-muted transition-colors">
                Training
                <svg :class="(open || {{ $onTrainingPage ? 'true' : 'false' }}) ? 'rotate-90' : ''"
                     class="w-3 h-3 transition-transform duration-150" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
            <div x-show="open || {{ $onTrainingPage ? 'true' : 'false' }}" x-cloak class="space-y-0.5">
                <a href="{{ route('wow-quiz') }}" wire:navigate
                   class="sidebar-item text-[12px] {{ request()->routeIs('wow-quiz*') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Class quizzes
                </a>
                <a href="{{ route('strategy') }}" wire:navigate
                   class="sidebar-item text-[12px] {{ request()->routeIs('strategy') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    Strategy
                </a>
                {{-- Moved here from Explore, 2026-09-24. It is a document you read to understand
                     the model, which is what the rest of this section is; next to the comp tools
                     it read as another tool. --}}
                <a href="{{ route('brain') }}" wire:navigate
                   class="sidebar-item text-[12px] {{ request()->routeIs('brain') ? 'active !text-accent' : '' }}">
                    <span class="w-1 h-1 rounded-full bg-current shrink-0"></span>
                    The Brain
                </a>
                {{-- Diagnostic, Quizzes and Progress are hidden (2026-09-17): they belong to the old
                     learning-module system. The routes still work; the links come back once class
                     quizzes are built out. See CLAUDE.md, "Old Training pages hidden". --}}
            </div>
        </div>

        @can('admin')
        <!-- Creator -->
        {{-- Admin only. Collapsed by default (new persist key, so it starts closed for everyone once). --}}
        <div x-data="{ creatorOpen: $persist(false).as('nav_creator_open_v2') }" class="pt-3">
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

    {{-- Feedback, Discord and Buy me a coffee: one quiet row rather than three full-size nav items.
         They are real, and worth one click, but not worth competing with the site's own pages.
         The coffee link replaced a Stripe checkout button (2026-09-14) and shows for everyone,
         signed in or not, but only once BUYMEACOFFEE_URL is set. --}}
    <div class="shrink-0 px-3 py-2 flex items-center gap-3 flex-wrap text-[11.5px] text-ink-subtle">
        <a href="{{ route('feedback.create') }}" wire:navigate class="hover:text-ink transition-colors {{ request()->routeIs('feedback.*') ? 'text-gold' : '' }}">Feedback</a>
        <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="hover:text-ink transition-colors">Discord</a>
        @if (filled(config('services.buymeacoffee.url')))
            <a href="{{ route('support') }}" target="_blank" rel="noopener noreferrer" class="hover:text-gold transition-colors">&#9749; Buy me a coffee</a>
        @endif
    </div>

    <!-- Footer: the account menu (auth) or sign-in prompt (guest) -->
    <div class="shrink-0 border-t border-line">
        @auth
        <!-- User menu -->
        <div x-data="{ open: false }" class="relative px-2 py-2">
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
                {{-- Credits and XP belong to the quiz side (Training); moved here from a row that sat
                     permanently above this menu on every page. --}}
                <div class="flex items-center justify-between px-3 py-2 border-b border-line text-[11px] text-ink-subtle">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-gold shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Credits <span class="text-ink-muted font-medium">{{ $nav_ai_credits }}</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        XP <span class="text-ink-muted font-medium">{{ $nav_learned_credits }}</span>
                    </span>
                </div>
                <a href="{{ route('profile.edit') }}" wire:navigate
                   class="flex items-center gap-2 px-3 py-2 text-[12px] text-ink-muted hover:text-ink hover:bg-surface-3 transition-colors">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Profile &amp; settings
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

