<?php

namespace App\Livewire;

use App\Http\Services\GuideFeed;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The public front page, at the site root (2026-09-16).
 *
 * Until now '/' redirected straight to the comp builder, and the feed of game plans only existed
 * on the signed-in Home page — so a visitor never saw that anyone was writing guides here at all.
 * This page leads with what the site is for, offers the comp builder as the first action (the
 * common comps open straight into it), and shows the public feed beneath.
 *
 * The builder is deliberately linked, not embedded. It is the heaviest page on the site, and
 * stacking a feed under it would hide the feed below the fold while making every visit pay for both.
 *
 * Signed-in players are sent to Home, which has the same feed plus their own guides, characters
 * and friends. The stream is built by GuideFeed with no viewer, which limits it to public guides
 * and applies the front-page rules documented there (no tooltip-only updates, a per-author cap,
 * popular guides while the feed is thin).
 *
 * 2026-10-12 (Chriso): the page now leads with the desktop app, the product: a download, a replica of
 * the app showing an example game, and three steps. The planning tools (comps, Matchup Lab, class
 * guides, guides and their feed) follow it. The example game is made up and says so, because a real
 * game's page names real players.
 */
class Landing extends Component
{
    public const FEED_PAGE = 10;

    public const FEED_MAX = 50;

    /** Locked so a tampered request cannot ask for the whole table; loadMore() caps it. */
    #[Locked]
    public int $feedLimit = self::FEED_PAGE;

    public function mount()
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        PageViewEvent::log('landing');
    }

    public function loadMore(): void
    {
        PageViewEvent::log('landing_feed', slot: 'more');

        $this->feedLimit = min($this->feedLimit + self::FEED_PAGE, self::FEED_MAX);
        unset($this->feed);
    }

    /** @return array{items: array<int, array>, hasMore: bool} */
    #[Computed]
    public function feed(): array
    {
        return app(GuideFeed::class)->build(null, 'everyone', $this->feedLimit, self::FEED_PAGE);
    }

    /** The common comps, each opening straight into the builder. */
    #[Computed]
    public function presets(): array
    {
        return WowComps::presetLinks();
    }

    /**
     * How many games and players every comparison is built from: the live norms (BuildPopulation),
     * so the number on the page is the real one and grows with every upload.
     *
     * @return array{games: int, players: int, specs: int}
     */
    #[Computed]
    public function pool(): array
    {
        $path = \App\Console\Commands\BuildPopulation::normsPath();
        $n = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        return ['games' => (int) ($n['games'] ?? 0), 'players' => (int) ($n['players'] ?? 0), 'specs' => count($n['specs'] ?? [])];
    }

    /** The example game's spell icons, by name (null where the data has none: a plain square). */
    #[Computed]
    public function icons(): array
    {
        $names = ['Kill Command', 'Bloodshed', 'Chaos Bolt', 'Barbed Shot', 'Kill Shot', 'Icebound Fortitude', 'Anti-Magic Shell', 'Fear', 'Counter Shot', 'Pain Suppression'];

        return \App\Models\Spell::query()
            ->where('patch_id', \App\Models\Patch::where('is_current', true)->value('id'))
            ->whereIn('name', $names)->whereNotNull('icon_name')
            ->orderBy('is_passive')->get(['name', 'icon_name'])
            ->unique('name')->mapWithKeys(fn ($s) => [$s->name => '/storage/spell-icons/'.$s->icon_name])->all();
    }

    #[Computed]
    public function appAvailable(): bool
    {
        return is_file(\App\Http\Controllers\DesktopAppController::path());
    }

    public function render()
    {
        return view('livewire.landing')->layout('layouts.app', [
            'title' => 'MindCollector: every WoW arena game you play, reviewed',
            'description' => 'A free Windows app that reviews every arena game you play: who died, what killed them, what you had left, and how you compare with players of your spec. Plus 3v3 comps, class guides and the Matchup Lab.',
        ]);
    }
}
