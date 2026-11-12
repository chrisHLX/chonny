{{-- The app's WoW Forever page (/wow/forever, 2026-11-12; laid out as a product page the same evening,
     Chriso: "make the forever page on the website nice"). What the app and its Observer addon do in
     Forever, and how to start. Same download as /wow/app. See AppDownloadController::forever and
     docs/forever/observer-handoff-2026-11-10.md. Every claim here is what app 0.6.12 does: keep it true.
     The pictures are the addon's own window, taken from the game (public/images/forever/). --}}
@php
    $shots = collect([
        ['professions', 'Professions', 'What levels you cheapest, and what each craft earns after the auction house\'s cut.'],
        ['market', 'Market', 'The whole auction house between two scans, by category, with what\'s actually selling.'],
    ])->filter(fn ($s) => is_file(public_path("images/forever/{$s[0]}.webp")))->values();
@endphp
<x-app-layout title="MindCollector for WoW Forever: Professions, Auction Prices and PvP Clips" description="Free Windows app and addon for WoW Forever. See which recipes level you cheapest and which earn the most, read the auction house for what's selling, and save a video of every PvP fight.">
<div class="max-w-5xl mx-auto px-4 py-6 sm:py-10">

    {{-- Hero --}}
    <section class="page-hero">
        <p class="page-eyebrow">WoW Forever · Free for Windows 10 and 11</p>
        <h1 class="page-title">Crafting, Prices and PvP in WoW Forever</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl leading-relaxed">
            An addon that works out which recipes level you cheapest and which ones earn the most, reads the
            whole auction house for what's actually selling, and shows what every item is used for. With OBS,
            the app also saves a video of every fight you have against another player.
        </p>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            @if ($available)
                <a href="{{ route('coach.app') }}" download class="btn-primary !px-8 !py-3 !text-[15px]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                    Download for Windows
                </a>
                <a href="#start" class="btn-secondary !px-5 !py-3 !text-[14px]">How to start</a>
                <span class="text-[13px] text-ink-subtle">Already have the app? It updates itself.</span>
            @else
                <p class="text-[14px] text-ink-muted">The download isn't up yet. Check back soon.</p>
            @endif
        </div>
    </section>

    {{-- The addon's own window --}}
    @if ($shots->isNotEmpty())
        <section class="mt-9" x-data="{ i: 0 }" aria-label="The addon's window in WoW Forever">
            <div class="rounded-xl bg-frame p-2 shadow-2xl shadow-black/60 ring-1 ring-white/5">
                <div class="flex flex-wrap items-center gap-2 px-2 pb-2">
                    <span class="w-3 h-3 rounded-full bg-gold/80"></span>
                    <span class="text-[12px] text-ink-muted mr-2">In the game: type /mco</span>
                    @foreach ($shots as $k => [$key, $label])
                        <button type="button" @click="i = {{ $k }}"
                            class="px-3 py-1 rounded-full text-[12.5px] font-semibold transition-colors"
                            :class="i === {{ $k }} ? 'bg-ink text-black' : 'bg-surface-2 text-ink-muted hover:text-ink'">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="relative rounded-lg overflow-hidden bg-surface-0">
                    @foreach ($shots as $k => [$key, $label, $caption])
                        <img src="/images/forever/{{ $key }}.webp" alt="The MindCollector Observer window in WoW Forever: {{ $label }}"
                             loading="{{ $k === 0 ? 'eager' : 'lazy' }}" width="924" height="548"
                             class="w-full h-auto {{ $k > 0 ? 'hidden' : '' }}" x-show="i === {{ $k }}" @if ($k > 0) x-cloak @endif>
                    @endforeach
                </div>
            </div>
            @foreach ($shots as $k => [$key, $label, $caption])
                <p class="text-[13px] text-ink-muted mt-3 px-1" x-show="i === {{ $k }}" @if ($k > 0) x-cloak @endif>{{ $caption }}</p>
            @endforeach
        </section>
    @endif

    {{-- What it does --}}
    <h2 class="text-[24px] font-bold text-ink mt-12 mb-4">What the addon does</h2>
    <div class="grid sm:grid-cols-2 gap-3">
        <div class="linear-card p-5">
            <div class="flex items-center gap-3 mb-3">
                <x-mc-icon name="icon-flask" class="w-6 h-6 text-gold"/>
                <h3 class="text-[17px] font-bold text-ink">Professions</h3>
            </div>
            <p class="text-[14px] text-ink-muted leading-relaxed">
                Every recipe that still gives you a skill point, cheapest per point first, priced from the auction
                house. Switch to what each craft earns after the 5% cut, whether or not it gives a point.
            </p>
            <ul class="mt-3 space-y-1.5 text-[13.5px] text-ink-muted">
                <li class="flex gap-2"><span class="text-gold">•</span>Counts reagents you can make yourself across all your professions, so a blacksmith who mines smelts their own bars</li>
                <li class="flex gap-2"><span class="text-gold">•</span>How many one craft makes, for every profession</li>
                <li class="flex gap-2"><span class="text-gold">•</span>Where each recipe comes from: the trainer's skill and fee, or the vendors and drops for its recipe</li>
            </ul>
        </div>
        <div class="linear-card p-5">
            <div class="flex items-center gap-3 mb-3">
                <x-mc-icon name="icon-delta" class="w-6 h-6 text-gold"/>
                <h3 class="text-[17px] font-bold text-ink">The auction house</h3>
            </div>
            <p class="text-[14px] text-ink-muted leading-relaxed">
                One click reads every listing. Two scans show the biggest price moves, which you can narrow to
                materials, consumables or gear, then to herbs, cloth, potions and the rest.
            </p>
            <ul class="mt-3 space-y-1.5 text-[13.5px] text-ink-muted">
                <li class="flex gap-2"><span class="text-gold">•</span>Sort by rising, falling, selling, flooding, most listed or most gold on sale</li>
                <li class="flex gap-2"><span class="text-gold">•</span>"At least 12 sold" only counts what expiring listings can't explain: each scan notes how long every listing has left</li>
                <li class="flex gap-2"><span class="text-gold">•</span>Track an item to keep every reading of its price and listings</li>
            </ul>
        </div>
        <div class="linear-card p-5">
            <div class="flex items-center gap-3 mb-3">
                <x-mc-icon name="icon-compass" class="w-6 h-6 text-gold"/>
                <h3 class="text-[17px] font-bold text-ink">What every item is used for</h3>
            </div>
            <p class="text-[14px] text-ink-muted leading-relaxed">
                Under any item, every recipe in any profession that takes it, grouped by profession with yours first,
                and the quests that ask for it, the ones you can take first.
            </p>
            <ul class="mt-3 space-y-1.5 text-[13.5px] text-ink-muted">
                <li class="flex gap-2"><span class="text-gold">•</span>What each craft sells for, and whether it's used at level 60</li>
                <li class="flex gap-2"><span class="text-gold">•</span>Click a recipe to open it in Professions</li>
            </ul>
        </div>
        <div class="linear-card p-5">
            <div class="flex items-center gap-3 mb-3">
                <x-mc-icon name="icon-scroll" class="w-6 h-6 text-gold"/>
                <h3 class="text-[17px] font-bold text-ink">Your ledger</h3>
            </div>
            <p class="text-[14px] text-ink-muted leading-relaxed">
                What you craft, buy at the auction house and vendors, post and sell, kept as you play, so you can
                see what each item cost you and what it actually earned.
            </p>
        </div>
    </div>

    {{-- Shared prices --}}
    <div class="mt-3 rounded-xl p-6 bg-gold-subtle ring-1 ring-line-gold">
        <div class="flex items-center gap-3 mb-2">
            <x-mc-icon name="icon-axis-hex" class="w-6 h-6 text-gold"/>
            <h3 class="text-[17px] font-bold text-ink">Prices from everyone's scans</h3>
        </div>
        <p class="text-[14px] text-ink-muted leading-relaxed max-w-3xl">
            Everyone who uses the app shares their auction scans, so your prices are as fresh as the latest scan
            anyone on your side made, even before you've scanned yourself. Horde, Alliance and the goblin auction
            houses in Booty Bay, Gadgetzan and Everlook each have their own listings, and the app keeps them apart.
        </p>
    </div>

    {{-- PvP on video --}}
    <h2 class="text-[24px] font-bold text-ink mt-12 mb-4">Your PvP fights on video</h2>
    <div class="linear-card p-6 flex flex-col md:flex-row gap-6 items-start">
        <div class="flex-1 space-y-3 text-[14px] text-ink-muted leading-relaxed">
            <p>With OBS Studio (free), the app saves each fight you have against another player: duels, world PvP and
                battlegrounds. It keeps the last ten minutes in memory and, when a fight ends, saves it from a minute
                before the first hit to a few seconds after the last. Both are sliders in Settings. Fights against
                monsters aren't saved.</p>
            <p>In the app's Settings, <span class="text-ink font-semibold">Set up OBS</span> does the work: it installs
                OBS if you don't have it, turns on what the app needs, records on your graphics card, and only says
                ready after a short test recording has worked. Recordings carry WoW's own sound only, so voice chat
                and music stay out, unless you choose otherwise.</p>
        </div>
        <div class="flex flex-wrap md:flex-col md:items-start gap-2 shrink-0">
            <span class="badge-gold">Tested before it says ready</span>
            <span class="badge-gold">WoW's sound only</span>
            <span class="badge-gold">Graphics card encoding</span>
        </div>
    </div>

    {{-- Getting started --}}
    <h2 id="start" class="text-[24px] font-bold text-ink mt-12 mb-4 scroll-mt-20">Getting started</h2>
    <ol class="grid md:grid-cols-3 gap-3">
        <li class="linear-card p-5">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center">1</span>
            <p class="text-[16px] font-bold text-ink mt-3">Install or update the app</p>
            <p class="text-[14px] text-ink-muted mt-1">
                Download it and open it. If Windows says it "protected your PC", click
                <span class="text-ink font-semibold">More info</span>, then <span class="text-ink font-semibold">Run anyway</span>.
                New to the app? It opens this website so you can connect your account, as on
                <a href="{{ route('app') }}" class="text-gold hover:underline">the app page</a>.
            </p>
        </li>
        <li class="linear-card p-5">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center">2</span>
            <p class="text-[16px] font-bold text-ink mt-3">Restart WoW Forever once</p>
            <p class="text-[14px] text-ink-muted mt-1">
                The app installs its addons into Forever by itself. WoW only finds new addons when it starts, so close
                Forever completely and open it again. A <code class="text-gold">/reload</code> isn't enough the first time.
            </p>
        </li>
        <li class="linear-card p-5">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center">3</span>
            <p class="text-[16px] font-bold text-ink mt-3">Open it and scan</p>
            <p class="text-[14px] text-ink-muted mt-1">
                Type <code class="text-gold">/mco</code> or click the spyglass by the minimap. At an auction house, click
                <span class="text-ink font-semibold">Scan auction house</span>. The game allows one full scan every 15
                minutes, and your scan is shared after your next <code class="text-gold">/reload</code> or logout.
            </p>
        </li>
    </ol>
    <div class="linear-card p-5 flex gap-4 mt-3">
        <span class="w-9 h-9 rounded-full bg-surface-3 text-ink font-bold flex items-center justify-center shrink-0 text-[13px]">+</span>
        <div>
            <p class="text-[16px] font-bold text-ink">Record your fights (optional)</p>
            <p class="text-[14px] text-ink-muted mt-1">
                In the app's Settings, tick <span class="text-ink font-semibold">Save each PvP fight in WoW Forever</span>,
                click <span class="text-ink font-semibold">Set up OBS</span>, then <span class="text-ink font-semibold">Save</span>.
                The light at the bottom of the app turns green when the next fight will be recorded, and says what's wrong
                if it won't.
            </p>
        </div>
    </div>

    {{-- Questions --}}
    <h2 class="text-[24px] font-bold text-ink mt-12 mb-3">Questions</h2>
    <div class="space-y-2">
        <details class="linear-card p-5">
            <summary class="cursor-pointer text-[15px] font-bold text-ink">What does it read, and what does it share?</summary>
            <div class="text-[14px] text-ink-muted mt-3 space-y-2">
                <p>In Forever it reads two things: your combat log, to find your PvP fights, and the addon's own saved file.</p>
                <p>It shares your auction scans: for each item the cheapest price, how many are listed and how long they have
                    left, with your realm and which auction house it was. You can turn this off in Settings
                    (<span class="text-ink font-semibold">Share my auction house scans</span>), and you still get everyone's prices.</p>
                <p>Everything else stays on your PC: what you looked at, your ledger, what you bought and sold, and your videos.</p>
            </div>
        </details>
        <details class="linear-card p-5">
            <summary class="cursor-pointer text-[15px] font-bold text-ink">Will it slow my game down?</summary>
            <div class="text-[14px] text-ink-muted mt-3 space-y-2">
                <p>The addon does nothing while you play except notice what you hover over and what the auction house, vendors,
                    trainers and mail show you. A full auction house scan takes a second or two to read.</p>
                <p>Recording is OBS's job, on your graphics card. On our test PC, recording with OBS's game capture made no
                    measurable difference to WoW's frame rate.</p>
            </div>
        </details>
        <details class="linear-card p-5">
            <summary class="cursor-pointer text-[15px] font-bold text-ink">Is the addon allowed?</summary>
            <div class="text-[14px] text-ink-muted mt-3 space-y-2">
                <p>It's an ordinary addon: it uses the same addon features as any other, and the app only reads files WoW writes.
                    Nothing of the app's runs inside the game.</p>
            </div>
        </details>
        <details class="linear-card p-5">
            <summary class="cursor-pointer text-[15px] font-bold text-ink">Still stuck?</summary>
            <div class="text-[14px] text-ink-muted mt-3 space-y-2">
                <p>Tell us on <a href="https://discord.gg/Bk7wEvPRt" target="_blank" rel="noopener noreferrer" class="text-gold hover:underline">Discord</a>
                    or through the <a href="{{ route('feedback.create') }}" class="text-gold hover:underline">feedback page</a>,
                    with the last few lines from the app's <span class="text-ink font-semibold">Activity</span> window.</p>
            </div>
        </details>
    </div>

    @if ($available)
        <div class="mt-12 rounded-xl p-8 bg-surface-1 text-center">
            <p class="text-[20px] font-bold text-ink">Ready to try it?</p>
            <p class="text-[14px] text-ink-muted mt-2">Free, about 70 MB, and it updates itself.</p>
            <a href="{{ route('coach.app') }}" download class="btn-primary !px-8 !py-3 !text-[15px] inline-flex mt-5">Download for Windows</a>
        </div>
    @endif
</div>
</x-app-layout>
