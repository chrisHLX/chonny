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
    </head>
    <body class="font-sans antialiased bg-surface-0 text-ink">
        <div class="min-h-screen flex flex-col items-center justify-center px-4">
            <!-- Logo -->
            <a href="{{ url('/') }}" class="flex items-center gap-2 mb-8 hover:opacity-90 transition-opacity">
                <svg class="w-7 h-7 text-gold shrink-0" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 3 L35 11.5 L35 28.5 L20 37 L5 28.5 L5 11.5 Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/>
                    <path d="M11 29 L11 12 L20 20.5 L29 12 L29 29" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    <path d="M15 26 A5 5 0 0 1 25 26" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
                </svg>
                <span class="font-display text-[16px] font-bold tracking-wide">
                    <span class="text-ink">Mind</span><span class="text-gold">Collector</span>
                </span>
            </a>

            <div class="w-full max-w-sm linear-card px-6 py-6">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
