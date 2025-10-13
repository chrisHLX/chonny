<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Learnplayai') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @livewireScriptConfig
            @include('layouts.navigation')

            <!-- Page Heading DISABLED -->
             <!--
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset
            -->
            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
        <!-- Spinner for page loading -->
        <div id="global-loading-spinner"
            class="fixed inset-0 bg-white bg-opacity-80 z-50 flex items-center justify-center hidden">
            <div class="text-center">
                <svg class="animate-spin h-10 w-10 text-indigo-600 mx-auto" xmlns="http://www.w3.org/2000/svg"
                    fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <p class="mt-2 text-indigo-600 font-semibold">Loading...</p>
            </div>
        </div>

        <script>
            window.addEventListener('beforeunload', () => {
                document.getElementById('global-loading-spinner').classList.remove('hidden');
            });
        </script>
        
        
    </body>
</html>
