<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "Comps this season" on the 3v3 comps page (/wow/comps, 2026-10-19): every 3v3 comp in the games
 * uploaded to MindCollector this season, with its record, the MMR and experience of the teams that
 * played it, and how it did against each comp it met (CompLeaderboardService). No player is named.
 *
 * Open to everyone; a comp's card (its goes, CC chains, deaths and every game) opens on its own
 * page, which asks for an account with the app connected (CompsPlayed). Lazy, so the comps page,
 * already the heaviest on the site, draws first and a crawler never computes the board.
 */
#[Lazy]
class CompBoard extends Component
{
    /** Comps shown before "Show every comp". */
    public const SHOWN = 15;

    public bool $all = false;

    public function mount(): void
    {
        PageViewEvent::log('comp_board');
    }

    public function showAll(): void
    {
        $this->all = true;
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
            'comps' => $this->all ? $board['comps'] : array_slice($board['comps'], 0, self::SHOWN),
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
            'colors' => config('wow_classes.colors', []),
        ]);
    }
}
