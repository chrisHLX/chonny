<?php

namespace App\Http\Services;

use App\Models\ArenaRoundPlayer;
use App\Models\Specialization;
use App\Support\PlayerUrl;
use Illuminate\Support\Collection;

/**
 * The games a player has been seen in, for their public page (2026-10-19, Chriso: "We should have
 * the match data for players just show everything for now"): every uploaded round they were in,
 * whoever uploaded it, with both teams and what the round measured for each player.
 *
 * Read from arena_round_players only, never a round's payload. A match two teammates both uploaded
 * is one game here (by match id). Partners are counted from 2v2 and 3v3 only: a Shuffle teammate
 * is drawn, not chosen.
 */
class PlayerGamesService
{
    /** @var array<int, array{label: string, class: ?string}>|null */
    private ?array $specs = null;

    /**
     * @return array{total: int, won: int, lost: int, first: ?\Carbon\Carbon, last: ?\Carbon\Carbon, specs: array<string, int>, brackets: array<string, int>, partners: list<array>, games: list<array>}
     */
    public function for(string $region, string $realmKey, string $name, int $limit = 20): array
    {
        $mine = ArenaRoundPlayer::query()
            ->where('region', $region)->where('realm_key', $realmKey)->where('name', $name)
            ->orderByDesc('played_at')->orderByDesc('id')
            ->get()
            ->unique('match_id')
            ->values();

        if ($mine->isEmpty()) {
            return ['total' => 0, 'won' => 0, 'lost' => 0, 'first' => null, 'last' => null, 'specs' => [], 'brackets' => [], 'partners' => [], 'games' => []];
        }

        $roundIds = $mine->pluck('arena_round_id');
        $everyone = ArenaRoundPlayer::whereIn('arena_round_id', $roundIds)->get()->groupBy('arena_round_id');

        return [
            'total' => $mine->count(),
            'won' => $mine->where('won', true)->count(),
            'lost' => $mine->where('won', false)->count(),
            'first' => $mine->last()->played_at,
            'last' => $mine->first()->played_at,
            'specs' => $mine->countBy(fn ($r) => $this->spec($r->spec_external_id)['label'])->sortDesc()->all(),
            'brackets' => $mine->countBy('bracket')->sortDesc()->all(),
            'partners' => $this->partners($mine, $everyone),
            'games' => $mine->take($limit)->map(fn ($r) => $this->game($r, $everyone[$r->arena_round_id] ?? collect()))->all(),
        ];
    }

    private function partners(Collection $mine, Collection $everyone): array
    {
        $tally = [];
        foreach ($mine as $me) {
            if (! in_array($me->bracket, ['2v2', '3v3'], true)) {
                continue;
            }
            foreach ($everyone[$me->arena_round_id] ?? [] as $p) {
                if ($p->guid === $me->guid || $p->side !== $me->side) {
                    continue;
                }
                $key = $p->logName();
                $tally[$key] ??= ['player' => $p, 'games' => 0, 'won' => 0];
                $tally[$key]['games']++;
                $tally[$key]['won'] += $me->won ? 1 : 0;
            }
        }

        return collect($tally)->sortByDesc('games')->take(8)
            ->map(fn ($t) => $this->player($t['player']) + ['games' => $t['games'], 'won' => $t['won']])
            ->values()->all();
    }

    private function game(ArenaRoundPlayer $me, Collection $players): array
    {
        $team = $players->where('side', $me->side)->sortByDesc('healer');
        $enemy = $players->where('side', '!=', $me->side)->sortByDesc('healer');

        return [
            'match' => $me->match_id,
            'playedAt' => $me->played_at,
            'bracket' => str_replace(['Rated Solo Shuffle', 'Rated BG Blitz'], ['Shuffle', 'Blitz'], $me->bracket),
            'seconds' => $me->seconds,
            'won' => $me->won,
            'mmr' => $me->mmr,
            'mmrThem' => $enemy->first()?->mmr,
            'me' => $this->player($me),
            'team' => $team->map(fn ($p) => $this->player($p) + ['isMe' => $p->guid === $me->guid])->values()->all(),
            'enemy' => $enemy->map(fn ($p) => $this->player($p))->values()->all(),
        ];
    }

    private function player(ArenaRoundPlayer $p): array
    {
        $spec = $this->spec($p->spec_external_id);

        return [
            'name' => $p->name,
            'realm' => $p->realm,
            'region' => $p->region,
            'url' => PlayerUrl::to($p->region, $p->realm, $p->name, false),
            'spec' => $spec['label'],
            'class' => $p->class_slug ?? $spec['class'],
            'healer' => $p->healer,
            'damage' => $p->damage,
            'healing' => $p->healing,
            'taken' => $p->damage_taken,
            'deaths' => $p->deaths,
            'ilvl' => $p->ilvl,
            'pvpTalents' => $p->pvp_talents ?? [],
        ];
    }

    /** @return array{label: string, class: ?string} */
    public function spec(?int $externalId): array
    {
        $this->specs ??= Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->get()
            ->mapWithKeys(fn ($s) => [(int) $s->external_spec_id => ['label' => trim($s->name.' '.$s->gameClass->name), 'class' => $s->gameClass->slug]])
            ->all();

        return $this->specs[$externalId ?? 0] ?? ['label' => 'Unknown spec', 'class' => null];
    }
}
