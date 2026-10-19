<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One comp's card from the comp board (/wow/comps/played/{key}/{vs?}, 2026-10-19): its record and
 * matchups for anyone; the card itself (its goes, CC chains, deaths and every game, no player named)
 * only for an account with the MindCollector app connected. Chriso: "when they click it they have
 * to sign up to see the card and player data ... in order for people to see it they need to install
 * the app and sign up." Everyone else sees an example card, blurred, under what to do next.
 */
class CompsPlayed extends Component
{
    #[Locked]
    public string $key;

    #[Locked]
    public ?string $vs = null;

    public function mount(string $key, ?string $vs = null): void
    {
        $this->key = $key;
        $this->vs = $vs;

        if (! auth()->check()) {
            // Signing up or in comes back here (RegisteredUserController / login use intended()).
            session()->put('url.intended', request()->url());
        }

        PageViewEvent::log('comp_played', null, null, $vs ? 'matchup' : 'comp');
    }

    /** Who may open a comp card: an account with the app connected (or an admin). */
    public static function canOpen(?User $user): bool
    {
        return $user !== null && ($user->coach_token_at !== null || $user->is_admin);
    }

    public function render()
    {
        $board = app(CompLeaderboardService::class)->board();
        $comp = collect($board['comps'])->firstWhere('key', $this->key);
        abort_if($comp === null, 404);
        $match = $this->vs ? collect($comp['matchups'])->firstWhere('key', $this->vs) : null;
        abort_if($this->vs && $match === null, 404);

        $name = fn (array $c) => $c['nick'] ?? collect($c['classes'])->map(fn ($s) => CompLeaderboardService::className($s))->implode(' + ');
        $title = $name($comp).($match ? ' vs '.$name($match) : '');
        $user = auth()->user();

        return view('livewire.comps-played', [
            'comp' => $comp,
            'match' => $match,
            'title' => $title,
            'record' => $match ? [$match['won'], $match['lost'], $match['games']] : [$comp['won'], $comp['lost'], $comp['games']],
            'state' => match (true) {
                self::canOpen($user) => 'open',
                $user !== null => 'app',
                default => 'guest',
            },
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
        ])->layout('layouts.app', [
            'title' => $title.' this season: 3v3 comp review | MindCollector',
            'description' => $title.' in 3v3 this season: '.$comp['games'].' games uploaded to MindCollector, their goes, crowd control chains and who they kill.',
            'robots' => 'noindex',
        ]);
    }
}
