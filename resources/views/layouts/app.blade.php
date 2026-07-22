<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Mindcollector') }}</title>
        <link rel="canonical" href="https://mindcollector.com/">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-surface-0 text-ink">
        <div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
            @livewireScriptConfig

            @include('layouts.navigation')

            <!-- Mobile backdrop -->
            <div x-show="sidebarOpen"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/60 z-20 sm:hidden"
                 x-transition:enter="transition-opacity ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 style="display:none">
            </div>

            <!-- Main content -->
            <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
                <!-- Mobile top bar -->
                <div class="flex sm:hidden items-center gap-3 px-4 h-11 border-b border-gold/20 shrink-0">
                    <button @click="sidebarOpen = true"
                            class="text-ink-muted hover:text-ink p-1 -ml-1 rounded transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <a href="{{ auth()->check() ? route_with_context('dashboard') : route('modules.index') }}"
                       class="flex items-center gap-1.5 hover:opacity-90 transition-opacity">
                        <svg class="w-5 h-5 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                            <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
                        </svg>
                        <span class="text-[13px] font-semibold tracking-tight">
                            <span class="text-ink">Mind</span><span class="text-gold">Collector</span>
                        </span>
                    </a>
                </div>

                <main class="flex-1 overflow-y-auto">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <!-- Page transition loader -->
        <div id="global-loading-spinner"
             class="fixed inset-0 bg-surface-0/80 backdrop-blur-sm z-50 flex items-center justify-center hidden">
            <div class="flex flex-col items-center gap-3">
                <svg class="animate-spin h-5 w-5 text-gold" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
                <p class="text-[11px] text-ink-subtle tracking-wide">Loading</p>
            </div>
        </div>

        <script>
            window.addEventListener('beforeunload', () => {
                document.getElementById('global-loading-spinner').classList.remove('hidden');
            });
        </script>
    </body>
</html>
