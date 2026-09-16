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

    public function render()
    {
        return view('livewire.landing')->layout('layouts.app', [
            'title' => 'MindCollector — plan and share WoW arena game plans',
            'description' => 'Build a 3v3 comp, then write the game plan: the opener, the go, and the answers you need them to spend. See what other arena players are planning.',
        ]);
    }
}
