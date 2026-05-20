<nav x-data="{ open: false }" class="bg-white border-b border-gray-100 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">

            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
                <a href="{{ route_with_context('dashboard') }}">
                    <x-application-logo class="h-9 w-auto text-gray-800" />
                </a>
            </div>

            <!-- Main Links -->
            <div class="hidden sm:flex sm:items-center sm:space-x-6">
                <x-nav-link :href="route_with_context('dashboard')" :active="request()->routeIs('dashboard')">
                    Dashboard
                </x-nav-link>
                <x-nav-link :href="route_with_context('collection.index')" :active="request()->routeIs('collection.index')">
                    Collection
                </x-nav-link>
                <x-nav-link :href="route_with_context('questions.quiz.index')" :active="request()->routeIs('questions.quiz.index')">
                    Questions
                </x-nav-link>
                <x-nav-link :href="route_with_context('modules.index')" :active="request()->routeIs('modules.index')">
                    Modules
                </x-nav-link>

                <!-- Categories Dropdown -->
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open"
                            class="flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">
                        Categories
                        <svg :class="{ 'rotate-180': open }" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="open" @click.away="open = false" 
                         x-transition class="absolute mt-2 w-56 bg-white border rounded shadow-lg z-50 max-h-64 overflow-y-auto">
                        @foreach($nav_categories as $cat)
                            <a href="{{ route_with_context(request()->route()->getName(), ['category_id' => $cat->id]) }}"
                            class="block px-4 py-2 text-sm transition
                                    {{ request('category_id') == $cat->id ? 'bg-blue-600 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                                {{ $cat->name }}
                            </a>
                        @endforeach

                    </div>
                </div>
            </div>

            <!-- Right Section -->
            <div class="hidden sm:flex sm:items-center sm:space-x-4">
                <!-- Credits -->
                <div class="flex items-center gap-1 px-3 py-1 bg-blue-100 rounded-full text-sm font-medium text-blue-800">
                    AI: <span class="font-semibold text-blue-900">{{ $nav_ai_credits }}</span>
                </div>
                <div class="flex items-center gap-1 px-3 py-1 bg-green-100 rounded-full text-sm font-medium text-green-800">
                    Learned: <span class="font-semibold text-green-900">{{ $nav_learned_credits }}</span>
                </div>

                <!-- User Dropdown -->
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            {{ Auth::user()->name ?? 'Guest' }}
                            <svg class="ml-2 h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Profile</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                Log Out
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Mobile Hamburger -->
            <div class="-mr-2 flex items-center sm:hidden">
                <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100">
                    <svg :class="{ 'hidden': open, 'inline-flex': !open }" class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>


    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('collection.index')" :active="request()->routeIs('collection.index')">
                {{ __('collection') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('questions.quiz.index')" :active="request()->routeIs('questions.quiz.index')">
                {{ __('Questions') }}
            </x-responsive-nav-link>
        </div>

        <div :class="{ 'block': open, 'hidden': !open }" class="hidden sm:hidden px-4 pt-2 pb-3 space-y-1">
    <!-- Links -->
    <x-responsive-nav-link :href="route_with_context('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-responsive-nav-link>
    <x-responsive-nav-link :href="route_with_context('concepts.index')" :active="request()->routeIs('concepts.index')">Concepts</x-responsive-nav-link>

    <!-- Categories -->
    <div x-data="{ catOpen: false }" class="mt-2">
        <button @click="catOpen = !catOpen" class="flex items-center justify-between w-full px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md">
            Categories
            <svg :class="{ 'rotate-180': catOpen }" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="catOpen" x-transition class="mt-1 space-y-1">
            @foreach($nav_categories as $cat)
                <a href="{{ route_with_context(request()->route()->getName(), ['category_id' => $cat->id]) }}"
                class="block px-4 py-2 text-sm transition
                        {{ request('category_id') == $cat->id ? 'bg-blue-600 text-white font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                    {{ $cat->name }}
                </a>
            @endforeach

        </div>
    </div>
</div>


        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            @auth
                <div class="px-4">
                    <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
                </div>
                <!-- AI Credits -->
                <div class="flex items-center gap-1 px-3 py-1 bg-blue-100 rounded-full">
                    <span class="font-semibold text-blue-800 text-sm">AI:</span>
                    <span class="font-medium text-blue-900 text-sm">{{ $nav_ai_credits }}</span>
                </div>

                <!-- Learned Credits -->
                <div class="flex items-center gap-1 px-3 py-1 bg-green-100 rounded-full">
                    <span class="font-semibold text-green-800 text-sm">Learned:</span>
                    <span class="font-medium text-green-900 text-sm">{{ $nav_learned_credits }}</span>
                </div>
            @else
                <div class="px-4">
                    <a href="{{ route('login') }}" class="font-medium text-base text-gray-800">Login</a>
                </div>
            @endauth

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
