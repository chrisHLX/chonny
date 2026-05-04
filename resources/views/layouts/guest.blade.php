<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Mindcollector') }}</title>
        <link rel="canonical" href="https://mindcollector.com/">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-surface-0 text-ink">
        <div class="min-h-screen flex flex-col items-center justify-center px-4">
            <!-- Logo -->
            <div class="flex items-center gap-2.5 mb-8">
                <div class="w-6 h-6 rounded bg-accent flex items-center justify-center shrink-0">
                    <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                    </svg>
                </div>
                <span class="text-[15px] font-semibold text-ink tracking-tight">Mindcollector</span>
            </div>

            <div class="w-full max-w-sm linear-card px-6 py-6">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
