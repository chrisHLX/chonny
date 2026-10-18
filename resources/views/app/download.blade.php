{{-- The desktop app's page (/wow/app): what it does and how to get going. See AppDownloadController. --}}
@php
    $appSchema = \App\Http\Controllers\AppDownloadController::schema();
@endphp
<x-app-layout title="MindCollector for Windows: a review of every WoW arena game" description="Free Windows app for WoW arena. It sends your games to your page as you play, so every game gets a review: who died and why, and how you compare with players of your spec." og-image="/images/app/card.webp" :schema="$appSchema">
<div class="max-w-4xl mx-auto px-4 py-6 sm:py-10">

    <section class="page-hero">
        <p class="page-eyebrow">For Windows 10 and 11</p>
        <h1 class="page-title">See every arena game you play, reviewed.</h1>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            The MindCollector app watches your games while you play and sends each one to your page
            when it ends. You get a card for every game, and a page that sets your play against
            other players of your spec.
        </p>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            @if ($available)
                <a href="{{ route('coach.app') }}" download class="btn-primary !px-8 !py-3 !text-[15px]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                    Download for Windows
                </a>
                <span class="text-[13px] text-ink-subtle">About 70 MB. Nothing else to install.</span>
            @else
                <p class="text-[14px] text-ink-muted">The download isn't up yet. Check back soon.</p>
            @endif
        </div>
    </section>

    <x-app-showcase class="mt-8"/>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-4">Three steps</h2>
    <ol class="space-y-3">
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">1</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Open the file you downloaded</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    The app isn't signed by Microsoft yet, so Windows may show a blue box saying it
                    "protected your PC". Click <span class="text-ink font-semibold">More info</span>, then
                    <span class="text-ink font-semibold">Run anyway</span>. The app installs itself and adds
                    a shortcut to your Start menu and desktop.
                </p>
            </div>
        </li>
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">2</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Connect your account</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    The first time it opens, the app opens this website for you. Sign in (or make an
                    account) and click <span class="text-ink font-semibold">Connect</span>. The app picks
                    it up by itself, so there's no key to copy.
                </p>
            </div>
        </li>
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">3</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Play arena</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    The app adds a small WoW addon that turns on combat logging in arena. If WoW is already
                    open, type <code class="text-gold">/reload</code> once. Each game shows up in the app
                    about a minute after it ends, and on <a href="{{ route('coach') }}" class="text-gold hover:underline">Your games</a> here.
                </p>
            </div>
        </li>
    </ol>

    <p class="text-[13px] text-ink-subtle mt-6">
        The app sits in the tray by the clock and starts with Windows, so it's there when you play. You can
        turn that off in its Settings.
    </p>

    {{-- What it reads and what happens to it (2026-10-12), said before anyone installs it. --}}
    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">What it reads, and what happens to it</h2>
    <div class="linear-card p-5 space-y-2.5 text-[14px] text-ink-muted">
        <p><span class="text-ink font-semibold">It reads one thing:</span> your WoW combat log
            (WoWCombatLog.txt), and it only sends the arena games in it. Nothing else on your PC.</p>
        <p><span class="text-ink font-semibold">Your games are yours.</span> Each game's page names the
            players in it, so only you can see it.</p>
        <p><span class="text-ink font-semibold">The averages.</span> Unless you opt out, your games are also
            counted, without any names, in the averages everyone is compared with. You can leave them out
            any time on <a href="{{ route('coach') }}#privacy" class="text-gold hover:underline">Your games</a>, under Upload.</p>
        <p><span class="text-ink font-semibold">Your login stays with Blizzard.</span> You can make an
            account with an email address. If you use Battle.net, you sign in on Blizzard's own page and
            we never see your password.</p>
    </div>
</div>
</x-app-layout>
