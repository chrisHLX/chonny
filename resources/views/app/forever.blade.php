{{-- The app's WoW Forever page (/wow/forever, 2026-11-12): what the app and its Observer addon do in
     Forever, and how to start. Same download as /wow/app. See AppDownloadController::forever and
     docs/forever/observer-handoff-2026-11-10.md. Every claim here is what app 0.6.11 does. --}}
<x-app-layout title="MindCollector for WoW Forever: Professions, Auction Prices and PvP Clips" description="Free Windows app and addon for WoW Forever. See which recipes level you cheapest and which earn the most, read the auction house for what's selling, and save a video of every PvP fight.">
<div class="max-w-4xl mx-auto px-4 py-6 sm:py-10">

    <section class="page-hero">
        <p class="page-eyebrow">WoW Forever · Windows 10 and 11</p>
        <h1 class="page-title">Crafting, Prices and PvP in WoW Forever</h1>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            The MindCollector app adds an addon to WoW Forever that works out which recipes level you
            cheapest, which ones earn the most, and what is actually selling on the auction house. With
            OBS, the app also saves a video of every fight you have against another player.
        </p>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            @if ($available)
                <a href="{{ route('coach.app') }}" download class="btn-primary !px-8 !py-3 !text-[15px]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                    Download for Windows
                </a>
                <span class="text-[13px] text-ink-subtle">About 70 MB. Already have the app? It updates itself.</span>
            @else
                <p class="text-[14px] text-ink-muted">The download isn't up yet. Check back soon.</p>
            @endif
        </div>
    </section>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">Professions</h2>
    <div class="linear-card p-5 space-y-3 text-[14px] text-ink-muted">
        <p>For each of your professions, the addon lists the recipes that still give you a skill point, cheapest
            per point first, priced from the auction house. It counts reagents you can make yourself when that's
            cheaper, across all your professions, so a blacksmith who mines smelts their own bars.</p>
        <p>Switch the list to see what each craft earns after the auction house's 5% cut, whether or not it gives
            a point. Each recipe says how many one craft makes and where you get it: the trainer's skill and
            fee, or the vendors, drops and quests for its recipe. For Alchemy it already knows every trainer
            recipe and many recipe vendors and drops. Elsewhere it fills them in from the trainers and
            vendors you visit.</p>
        <p>Every item also shows what it's used in: each recipe in any profession that takes it, and the
            quests that ask for it.</p>
    </div>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">The auction house</h2>
    <div class="linear-card p-5 space-y-3 text-[14px] text-ink-muted">
        <p>With the auction house open, one click reads every listing. Two scans show the biggest price moves,
            which you can narrow to materials, consumables, gear and more, then to herbs, cloth, potions and
            so on.</p>
        <p>Fewer listings at the same price usually means people are buying, but listings also run out. Each
            scan notes how long every listing has left, so the addon only says "at least 12 sold" for the
            part of the drop that expiring can't explain. You can also sort for what's flooding: more listed
            and the price no higher.</p>
        <p>Prices are shared between everyone who uses the app, so your prices are as fresh as the latest
            scan anyone made. Horde, Alliance and the goblin auction houses in Booty Bay, Gadgetzan and
            Everlook each have their own listings, and the app keeps them apart.</p>
    </div>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">Your ledger</h2>
    <div class="linear-card p-5 text-[14px] text-ink-muted">
        <p>The addon keeps what you craft, buy at the auction house and vendors, post, and sell, so you can see
            what each item cost you and what it earned.</p>
    </div>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">PvP fights on video</h2>
    <div class="linear-card p-5 space-y-3 text-[14px] text-ink-muted">
        <p>With OBS Studio, the app saves each fight you have against another player: duels, world PvP and
            battlegrounds. It keeps the last ten minutes of video in memory and, when a fight ends, saves it
            from a minute before the first hit to a few seconds after the last. Both are sliders in
            Settings. Fights against monsters aren't saved.</p>
        <p>Don't have OBS? In the app's Settings, click <span class="text-ink font-semibold">Set up OBS</span>.
            It offers to install OBS for you (free, through Windows' own installer), then sets it up and adds a
            capture of WoW.</p>
    </div>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-4">Getting started</h2>
    <ol class="space-y-3">
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">1</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Install or update the app</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    Download it and open it. If you already have it, it updates itself when WoW isn't running, or
                    you can download it again to update now. Windows may say it "protected your PC": click
                    <span class="text-ink font-semibold">More info</span>, then <span class="text-ink font-semibold">Run anyway</span>.
                    New to the app? It opens this website so you can connect your account, as on
                    <a href="{{ route('app') }}" class="text-gold hover:underline">the app page</a>.
                </p>
            </div>
        </li>
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">2</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Restart WoW Forever once</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    The app installs its addons into Forever by itself. WoW only finds new addons when it starts, so
                    close Forever completely and open it again. A <code class="text-gold">/reload</code> isn't enough
                    the first time.
                </p>
            </div>
        </li>
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">3</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Open the window and scan</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    Type <code class="text-gold">/mco</code> or click the spyglass by the minimap. At an auction house,
                    click <span class="text-ink font-semibold">Scan auction house</span>. The game allows one full
                    scan every 15 minutes. The app shares it after your next <code class="text-gold">/reload</code>
                    or logout, and brings back everyone's prices.
                </p>
            </div>
        </li>
        <li class="linear-card p-5 flex gap-4">
            <span class="w-9 h-9 rounded-full bg-surface-3 text-ink font-bold flex items-center justify-center shrink-0 text-[13px]">+</span>
            <div>
                <p class="text-[16px] font-bold text-ink">Record your fights (optional)</p>
                <p class="text-[14px] text-ink-muted mt-1">
                    In the app's Settings, click <span class="text-ink font-semibold">Set up OBS</span>, tick
                    <span class="text-ink font-semibold">Save each PvP fight in WoW Forever</span>, and click
                    <span class="text-ink font-semibold">Save</span>. The light at the bottom of the app turns green
                    when the next fight will be recorded, and says what's wrong if it won't.
                </p>
            </div>
        </li>
    </ol>

    <h2 class="text-[22px] font-bold text-ink mt-10 mb-3">What it reads, and what it shares</h2>
    <div class="linear-card p-5 space-y-2.5 text-[14px] text-ink-muted">
        <p><span class="text-ink font-semibold">In Forever it reads two things:</span> your combat log, to find your
            PvP fights, and the Observer addon's own saved file.</p>
        <p><span class="text-ink font-semibold">It shares your auction scans.</span> Each scan sends the cheapest price,
            how many are listed and how long they have left for each item, with your realm and which auction house
            it was. You can turn this off in Settings
            (<span class="text-ink font-semibold">Share my auction house scans</span>), and you still get everyone's prices.</p>
        <p><span class="text-ink font-semibold">Everything else stays on your PC:</span> what you looked at, your ledger,
            what you bought and sold, and your videos.</p>
    </div>
</div>
</x-app-layout>
