<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The comp leaderboard (/wow/comps/leaderboard, 2026-10-21): every 3v3 comp in the games uploaded
 * to MindCollector this season, with its games, record, MMR and experience, filtered by the teams'
 * experience (CompLeaderboardService::EXP_BANDS). A row opens the comp's card, its real games
 * (CompsPlayed).
 *
 * Chriso: "the leaderboard doesnt display comp v Comp just comp filtered by EXP". The record against
 * each comp stays in the board's data (a card can still be opened for one matchup) but is not drawn
 * here. The 3v3 comps page keeps a top-five preview of this board (CompBoard).
 */
class CompLeaderboard extends Component
{
    /** Rows before "Show every comp". */
    public const SHOWN = 25;

    /**
     * The experience band, a key of EXP_BANDS; anything else reads as every team. In the URL so a
     * filtered board can be linked, and a string because a URL can carry anything.
     */
    #[Url(as: 'exp', except: null)]
    public ?string $exp = null;

    public bool $all = false;

    public function mount(): void
    {
        PageViewEvent::log('comp_leaderboard');
    }

    /** A band chosen on the page: the attributed count (a band arriving in a link is not a choice). */
    public function setExp(?int $exp): void
    {
        $this->exp = isset(CompLeaderboardService::EXP_BANDS[$exp]) ? (string) $exp : null;
        $this->all = false;
        PageViewEvent::log('comp_leaderboard', null, null, 'exp:'.($this->exp ?? 'all'));
    }

    public function showAll(): void
    {
        $this->all = true;
    }

    public function render()
    {
        $board = app(CompLeaderboardService::class)->board(ctype_digit((string) $this->exp) ? (int) $this->exp : null);

        return view('livewire.comp-leaderboard', [
            'board' => $board,
            'bands' => CompLeaderboardService::EXP_BANDS,
            'comps' => $this->all ? $board['comps'] : array_slice($board['comps'], 0, self::SHOWN),
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
            'colors' => config('wow_classes.colors', []),
        ])->layout('layouts.app', [
            'title' => '3v3 comp leaderboard this season | MindCollector',
            'description' => 'Every 3v3 arena comp this season from real games uploaded to MindCollector: games played, win rate, MMR and the experience of the teams playing it.',
        ]);
    }
}
