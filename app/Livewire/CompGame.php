<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One game from the comp board (/wow/comps/game/{round}, 2026-10-27): the card the app draws for
 * it, with no player named (GameCardService::pooled()), reached from a comp card's games and from
 * Leaderboards' Games tab. Chriso: "have in leaderboard individual matches so we can click on the
 * match card also when we get into the match card we need to be able to go back."
 *
 * The same rule as a comp card (CompsPlayed::canOpen()): any account opens it; signed out, a
 * sign-up over a blurred example. ?as=them reads it as the team the uploader played, which is how a
 * comp card reads every game; ?from and ?vs name the comp card to go back to.
 */
class CompGame extends Component
{
    #[Locked]
    public int $round;

    #[Locked]
    public string $as = 'us';

    #[Locked]
    public ?string $from = null;

    #[Locked]
    public ?string $vs = null;

    public function mount(int $round): void
    {
        $this->round = $round;
        $this->as = request()->query('as') === 'them' ? 'them' : 'us';
        $key = '/^[a-z]+(-[a-z]+){2,3}$/';
        $this->from = preg_match($key, (string) request()->query('from')) ? request()->query('from') : null;
        $this->vs = $this->from && preg_match($key, (string) request()->query('vs')) ? request()->query('vs') : null;

        if (! auth()->check()) {
            session()->put('url.intended', request()->fullUrl());
        }

        PageViewEvent::log('comp_game', null, null, $this->from ? 'from:comp' : 'from:board');
    }

    public function render()
    {
        $service = app(CompLeaderboardService::class);
        $sides = $service->teams()->where('round', $this->round)->keyBy('side');
        abort_if($sides->count() !== 2, 404);
        $us = $sides[$this->as === 'them' ? 'them' : 'us'];
        $them = $sides[$this->as === 'them' ? 'us' : 'them'];

        $name = function (string $key) {
            $label = CompLeaderboardService::label($key);

            return $label['nick'] ?? collect($label['classes'])->map(fn ($s) => CompLeaderboardService::className($s))->implode(' + ');
        };
        $title = $name($us['key']).' vs '.$name($them['key']);
        $back = $this->from
            ? ['url' => route('comps.played', array_filter(['key' => $this->from, 'vs' => $this->vs])).'#games', 'label' => $name($this->from).($this->vs ? ' vs '.$name($this->vs) : '')]
            : ['url' => route('leaderboards', ['tab' => 'games']), 'label' => 'Leaderboards'];

        return view('livewire.comp-game', [
            'title' => $title,
            'usName' => $name($us['key']),
            'us' => $us + ['label' => CompLeaderboardService::label($us['key'])],
            'them' => $them + ['label' => CompLeaderboardService::label($them['key'])],
            'back' => $back,
            'state' => CompsPlayed::canOpen(auth()->user()) ? 'open' : 'guest',
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
        ])->layout('layouts.app', [
            'title' => $title.': a 3v3 game | MindCollector',
            'description' => 'One 3v3 game uploaded to MindCollector, '.$title.': both teams, how each death happened, and everyone\'s damage and healing. No player is named.',
            'robots' => 'noindex',
        ]);
    }
}
