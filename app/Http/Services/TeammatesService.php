<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;
use App\Models\BattlenetCharacter;
use App\Models\PvpLeaderboardEntry;
use App\Models\Specialization;
use App\Models\User;
use App\Support\DesktopAsset;
use App\Support\PlayerUrl;
use Illuminate\Support\Collection;

/**
 * The Teammates pages: who a player queues with, how it went with each of them, and how their own
 * comps have done. The Comps page is the other half, the enemy comps they have met.
 *
 * Built from what is stored and nothing else: arena_round_players for the games, the logger from each
 * round's payload (the table does not say which row is the uploader), and battlenet_characters and
 * the leaderboards for a teammate's rating and titles. It never asks Blizzard, so a teammate nobody
 * has looked up simply has no rating here.
 *
 * 2v2 and 3v3 only. A Shuffle teammate is drawn, not chosen, as in PlayerGamesService. Each game
 * counts once by match id. Win rates under MatchAnalysisService::LEAD_BELOW games rank below every
 * larger record: a 3-0 partner is not ahead of a 40-20 one.
 */
class TeammatesService
{
    private const BRACKETS = ['2v2', '3v3'];

    /** One game together is a stranger from a queue. A page starts at the second. */
    private const MIN_GAMES_FOR_A_PAGE = 2;

    /** @var array<int, array{label: string, short: string, class: ?string, icon: ?string}>|null */
    private ?array $specs = null;

    public function signature(User $user): string
    {
        $rounds = ArenaRound::where('user_id', $user->id)->whereIn('bracket', self::BRACKETS)->orderBy('id')->get(['id', 'updated_at'])
            ->map(fn ($r) => $r->id.':'.$r->updated_at)->implode(',');

        // The date: a teammate's rating moves without a game of yours, so a build redraws once a day.
        return md5($rounds.'|'.date('Y-m-d').'|'.md5_file(__FILE__)
            .'|'.md5_file(resource_path('views/desktop/teammates.blade.php'))
            .'|'.md5_file(resource_path('views/desktop/teammate.blade.php')));
    }

    /**
     * @return array{overview: ?array, mates: array<string, array>}
     */
    public function build(User $user): array
    {
        $games = $this->games($user);
        if ($games === []) {
            return ['overview' => null, 'mates' => []];
        }

        $mates = $this->withStored($this->tally($games));
        $mates = $this->ranked($mates);
        $comps = $this->comps($games);
        $withPage = $mates->filter(fn ($m) => $m['games'] >= self::MIN_GAMES_FOR_A_PAGE)->values();

        $pages = [];
        foreach ($withPage as $i => $m) {
            $m['file'] = 'mate-'.substr(md5($m['key']), 0, 10).'.html';
            $pages[$m['key']] = array_intersect_key($m, array_flip(['key', 'name', 'color', 'games', 'won', 'lost', 'lead', 'file'])) + [
                'order' => $i + 1,
                'html' => view('desktop.teammate', ['m' => $m])->render(),
            ];
        }

        $files = array_column($pages, 'file', 'key');
        $overview = [
            'games' => count($games),
            'won' => count(array_filter($games, fn ($g) => $g['won'])),
            'mates' => $mates->count(),
        ];
        $overview['lost'] = $overview['games'] - $overview['won'];
        $overview['html'] = view('desktop.teammates', [
            'totals' => $overview,
            'comps' => $comps,
            'mates' => $withPage->map(fn ($m) => $m + ['file' => $files[$m['key']] ?? null])->all(),
            'once' => $mates->count() - $withPage->count(),
        ])->render();

        return ['overview' => $overview, 'mates' => $pages];
    }

    /** One entry per 2v2 or 3v3 game: the uploader's row and their teammates' rows. */
    private function games(User $user): array
    {
        $loggers = [];
        foreach (ArenaRound::where('user_id', $user->id)->whereIn('bracket', self::BRACKETS)->lazyById(50) as $r) {
            $logger = collect($r->payload['analysis']['players'] ?? [])->first(fn ($p) => ! empty($p['logger']))['guid'] ?? null;
            if ($logger !== null) {
                $loggers[$r->id] = $logger;
            }
            $r->setRawAttributes(array_diff_key($r->getAttributes(), ['payload' => true]), true);
        }

        $rows = collect();
        foreach (array_chunk(array_keys($loggers), 500) as $ids) {
            $rows = $rows->concat(ArenaRoundPlayer::whereIn('arena_round_id', $ids)->where('side', 'us')->get());
        }

        $games = [];
        foreach ($rows->groupBy('arena_round_id') as $roundId => $team) {
            $me = $team->firstWhere('guid', $loggers[$roundId]);
            if ($me === null || $me->won === null || isset($games[$me->match_id])) {
                continue;
            }
            // Four on our side of a 3v3: one of them was on the other team, and the row can't say
            // which (2 of 421 stored 3v3 rounds, 2026-11-01). Leave it out rather than guess.
            if ($team->count() > (int) $me->bracket[0]) {
                continue;
            }
            $games[$me->match_id] = [
                'bracket' => $me->bracket,
                'won' => $me->won,
                'mmr' => $me->mmr,
                'at' => $me->played_at,
                'me' => $me,
                'mates' => $team->reject(fn ($p) => $p->guid === $me->guid)->values(),
                'team' => $team->sortBy(fn ($p) => [$p->healer ? 1 : 0, $p->spec_external_id])->values(),
            ];
        }

        return array_values($games);
    }

    private function tally(array $games): Collection
    {
        $mates = [];
        foreach ($games as $g) {
            $comp = $this->compKey($g);
            foreach ($g['mates'] as $p) {
                $key = strtolower($p->region.'|'.$p->realm_key.'|'.$p->name);
                $mates[$key] ??= [
                    'key' => $key, 'name' => $p->name, 'realm' => $p->realm, 'realmKey' => $p->realm_key, 'region' => $p->region,
                    'class' => $p->class_slug, 'games' => 0, 'won' => 0, 'last' => null, 'mmr' => null,
                    'specs' => [], 'yours' => [], 'brackets' => [], 'comps' => [],
                ];
                $m = &$mates[$key];
                $m['games']++;
                $m['won'] += $g['won'] ? 1 : 0;
                $m['class'] ??= $p->class_slug;
                if ($m['last'] === null || $g['at'] > $m['last']) {
                    $m['last'] = $g['at'];
                }
                $m['mmr'] = max($m['mmr'] ?? 0, $g['mmr'] ?? 0) ?: null;
                $spec = $this->spec($p->spec_external_id);
                $m['specs'][$spec['label']] ??= ['games' => 0, 'icon' => $spec['icon']];
                $m['specs'][$spec['label']]['games']++;
                $m['yours'][$g['me']->name] = ($m['yours'][$g['me']->name] ?? 0) + 1;
                $this->count($m['brackets'][$g['bracket']], $g['won']);
                $m['comps'][$comp['key']] ??= ['label' => $comp['label'], 'bracket' => $g['bracket'], 'games' => 0, 'won' => 0];
                $this->count($m['comps'][$comp['key']], $g['won']);
                unset($m);
            }
        }

        return collect($mates)->map(function ($m) {
            $m['lost'] = $m['games'] - $m['won'];
            $m['rate'] = round($m['won'] / $m['games'] * 100);
            $m['lead'] = $m['games'] < MatchAnalysisService::LEAD_BELOW;
            $m['last'] = (string) $m['last']?->format('j M Y');
            $m['url'] = PlayerUrl::to($m['region'], $m['realm'], $m['name']);
            $m['color'] = config('wow_classes.colors')[$m['class']] ?? '#FFFFFF';
            uasort($m['specs'], fn ($a, $b) => $b['games'] <=> $a['games']);
            arsort($m['yours']);
            $m['comps'] = $this->sortRecords(array_values($m['comps']));

            return $m;
        });
    }

    /** Your own teams by bracket, best record first, on the same rule as teammates. */
    private function comps(array $games): array
    {
        $comps = [];
        foreach ($games as $g) {
            $comp = $this->compKey($g);
            $comps[$comp['key']] ??= ['label' => $comp['label'], 'bracket' => $g['bracket'], 'games' => 0, 'won' => 0];
            $this->count($comps[$comp['key']], $g['won']);
        }

        return $this->sortRecords(array_values($comps));
    }

    private function compKey(array $g): array
    {
        $specs = $g['team']->map(fn ($p) => $this->spec($p->spec_external_id));

        return ['key' => $g['bracket'].'|'.$g['team']->pluck('spec_external_id')->implode(','), 'label' => $specs->pluck('short')->implode(' + ')];
    }

    private function count(?array &$record, bool $won): void
    {
        $record['games'] = ($record['games'] ?? 0) + 1;
        $record['won'] = ($record['won'] ?? 0) + ($won ? 1 : 0);
    }

    private function sortRecords(array $records): array
    {
        $records = array_map(fn ($r) => $r + [
            'lost' => $r['games'] - $r['won'],
            'rate' => round($r['won'] / $r['games'] * 100),
            'lead' => $r['games'] < MatchAnalysisService::LEAD_BELOW,
        ], $records);
        usort($records, fn ($a, $b) => [$a['lead'], $a['lead'] ? -$a['games'] : -$a['rate'], -$a['games']] <=> [$b['lead'], $b['lead'] ? -$b['games'] : -$b['rate'], -$b['games']]);

        return $records;
    }

    private function ranked(Collection $mates): Collection
    {
        return $mates->sort(fn ($a, $b) => [$a['lead'], $a['lead'] ? -$a['games'] : -$a['rate'], -$a['games']] <=> [$b['lead'], $b['lead'] ? -$b['games'] : -$b['rate'], -$b['games']])->values();
    }

    /** What we already hold on each teammate: ratings, exp and titles. Never asks Blizzard. */
    private function withStored(Collection $mates): Collection
    {
        $names = $mates->pluck('name')->unique();
        if ($names->isEmpty()) {
            return $mates;
        }

        $key = fn ($region, $realm, $name) => strtolower($region.'|'.RealmDirectory::key($realm).'|'.$name);
        $characters = [];
        $boards = [];
        foreach ($names->chunk(500) as $chunk) {
            foreach (BattlenetCharacter::whereIn('name', $chunk)->get() as $c) {
                $characters[$key($c->region, $c->realm_slug, $c->name)] = $c;
            }
            foreach (PvpLeaderboardEntry::whereIn('name', $chunk)->where('bracket', '3v3')->orderBy('season')->get() as $e) {
                $boards[$key($e->region, $e->realm_slug, $e->name)] = (int) $e->rating;
            }
        }

        return $mates->map(function ($m) use ($characters, $boards) {
            $c = $characters[$m['key']] ?? null;
            $m['stored'] = $c !== null;
            $m['rating'] = null;
            $m['exp'] = $c?->bestExp();
            $m['titles'] = $c ? collect($c->bracketTitles())->reject(fn ($t) => $t['this_season'])->map(fn ($t) => [
                'text' => $t['title'].($t['count'] > 1 ? " \u{00D7}{$t['count']}" : ''),
                'where' => $t['bracket'] === 'Solo Shuffle' ? 'Shuffle' : $t['bracket'],
                'tip' => $t['tooltip'],
            ])->values()->all() : [];

            $best = $c ? ($c->currentRatings()[0] ?? null) : null;
            if ($best !== null) {
                $m['rating'] = ['rating' => (int) $best['rating'], 'bracket' => (string) ($best['label'] ?? '')];
            } elseif (isset($boards[$m['key']])) {
                $m['rating'] = ['rating' => $boards[$m['key']], 'bracket' => '3v3'];
            }

            return $m;
        });
    }

    private function spec(?int $externalId): array
    {
        $this->specs ??= Specialization::with('gameClass')->whereNotNull('external_spec_id')->get()
            ->mapWithKeys(fn ($s) => [(int) $s->external_spec_id => [
                'label' => trim($s->name.' '.$s->gameClass?->name),
                'short' => trim($s->name.' '.$s->gameClass?->name),
                'class' => $s->gameClass?->slug,
                'icon' => $s->icon_name ? DesktopAsset::url('spec-icons/'.$s->icon_name) : null,
            ]])->all();

        return $this->specs[(int) $externalId] ?? ['label' => 'Unknown spec', 'short' => 'Unknown spec', 'class' => null, 'icon' => null];
    }
}
