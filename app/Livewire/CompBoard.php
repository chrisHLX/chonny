<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "This season's top comps" on the 3v3 comps page (/wow/comps): a preview of the comp leaderboard
 * (CompLeaderboard, /wow/comps/leaderboard), from the games uploaded to MindCollector this season.
 *
 * Until 2026-10-21 this was the whole board, every comp with its matchups, between the slots and
 * the comp's guide. Chriso: show "just the top 5 different comps and MMR as a preview that allows
 * people to then click the comps leaderboard". The page is for choosing and learning a comp, so
 * the preview serves that: before a comp is picked it is the top five, and a row loads that comp's
 * usual line-up into the slots; once a comp is picked it is one line, that comp in real games,
 * with the way to its card (CompsPlayed) and to the leaderboard. The leaderboard is where real
 * games are browsed.
 *
 * Lazy, so the comps page, already the heaviest on the site, draws first and a crawler never
 * computes the board.
 */
#[Lazy]
class CompBoard extends Component
{
    /** Comps in the preview. */
    public const SHOWN = 5;

    /** The comp in the page's slots, as a board key; the parent remounts this when it changes. */
    #[Locked]
    public ?string $selected = null;

    public function mount(?string $selected = null): void
    {
        $this->selected = $selected;
        PageViewEvent::log('comp_board');
    }

    /** A row picked: count it, then hand its line-up to the page (WowComps::pickLineup()). */
    public function pick(string $key): void
    {
        $comp = collect(app(CompLeaderboardService::class)->board()['comps'])->firstWhere('key', $key);
        $ids = $comp['lineups'][0]['ids'] ?? [];
        if (count($ids) !== 3) {
            return;
        }

        PageViewEvent::log('comp_board', null, null, 'pick');
        $this->dispatch('pick-lineup', specs: $ids);
    }

    public function placeholder(): string
    {
        return '<div class="linear-card p-5 text-[13px] text-ink-subtle">Loading this season\'s comps&hellip;</div>';
    }

    public function render()
    {
        $board = app(CompLeaderboardService::class)->board();

        return view('livewire.comp-board', [
            'board' => $board,
            'comps' => array_slice($board['comps'], 0, self::SHOWN),
            'picked' => $this->selected ? collect($board['comps'])->firstWhere('key', $this->selected) : null,
            'pickedLabel' => $this->selected ? CompLeaderboardService::label($this->selected) : null,
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
        ]);
    }
}
