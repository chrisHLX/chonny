<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Your analysis": a player's own uploaded games, combined into the read match-review-analysis.md
 * was written as by hand for one team on 26 Sep 2026 — the review table, who they really played,
 * what differed between the games they won and lost, and a takeaway for their role.
 *
 * Built only from what RoundAnalysisService stored at upload (`arena_rounds.payload['analysis']`),
 * and each player's cached experience (PlayerExperienceService). Nothing here reads a raw log, so it
 * is cheap enough to build on a page load.
 *
 * EVERY COMPARISON CARRIES ITS SAMPLE, and anything resting on fewer than LEAD_BELOW games is
 * called a lead, never a finding: a description of these games, not a rule about the game.
 */
class MatchAnalysisService
{
    /** Below this many games on either side of a comparison, it is a lead. */
    private const LEAD_BELOW = 10;

    public function __construct(private PlayerExperienceService $experience, private SpellIconIndex $icons) {}

    /**
     * The days, brackets and teams this player has analysed games for, newest first.
     *
     * @return array<int, array{date: string, bracket: string, team: string, label: string, games: int}>
     */
    public function sessions(User $user): array
    {
        return $this->rounds($user)
            ->groupBy(fn ($r) => $r['date'].'|'.$r['bracket'].'|'.$r['teamKey'])
            ->map(fn (Collection $g) => [
                'date' => $g->first()['date'],
                'bracket' => $g->first()['bracket'],
                'team' => $g->first()['teamKey'],
                'label' => $g->first()['teamLabel'],
                'games' => $g->count(),
            ])
            ->sortByDesc(fn ($s) => $s['date'].sprintf('%05d', $s['games']))
            ->values()
            ->all();
    }

    public function build(User $user, string $date, string $bracket, string $team): ?array
    {
        $games = $this->rounds($user)
            ->filter(fn ($r) => $r['date'] === $date && $r['bracket'] === $bracket && $r['teamKey'] === $team)
            ->sortBy('playedAt')
            ->values();

        if ($games->isEmpty()) {
            return null;
        }

        $pending = 0;
        $xp = [];
        foreach ($games as $g) {
            foreach ($g['a']['players'] as $p) {
                if (! array_key_exists($p['name'], $xp)) {
                    $xp[$p['name']] = $this->experience->cached($p['name']);
                    $pending += $xp[$p['name']] === null ? 1 : 0;
                }
            }
        }

        $rows = $games->map(fn ($g) => $this->row($g, $xp))->all();
        $won = $games->filter(fn ($g) => $g['a']['won']);
        $lost = $games->reject(fn ($g) => $g['a']['won']);
        $logger = collect($games->first()['a']['players'])->firstWhere('logger', true);

        return [
            'date' => $date,
            'bracket' => $bracket,
            'record' => ['won' => $won->count(), 'lost' => $lost->count()],
            'team' => collect($games->first()['a']['players'])->where('side', 'us')->map(fn ($p) => [
                'name' => $this->short($p['name']), 'spec' => $p['spec'], 'xp' => $this->xpCell($xp[$p['name']] ?? null),
            ])->values()->all(),
            'games' => $rows,
            'levels' => collect($rows)->groupBy('level')->map(fn ($g) => ['games' => $g->count(), 'won' => $g->where('won', true)->count()])->all(),
            'whoYouPlayed' => $this->whoYouPlayed($won, $lost, $xp),
            'comparisons' => $this->comparisons($games, $won, $lost),
            'patterns' => $this->patterns($games, $won, $lost),
            'faults' => $this->faults($lost, $xp),
            'takeaways' => $logger ? $this->takeaways($logger, $games, $won, $lost) : [],
            'pendingExperience' => $pending,
        ];
    }

    // ------------------------------------------------------------------ the games

    /** Every analysed round this player owns, flattened to what grouping needs. */
    private function rounds(User $user): Collection
    {
        return ArenaRound::query()
            ->where('user_id', $user->id)
            ->orderBy('played_at')
            ->get()
            ->map(function (ArenaRound $r) {
                $a = $r->payload['analysis'] ?? null;
                if (! $a) {
                    return null;
                }
                $partners = collect($a['players'])->where('side', 'us')->reject(fn ($p) => $p['logger'])->sortBy('name');

                return [
                    'id' => $r->id,
                    'matchId' => $r->match_id,
                    'playedAt' => (string) $r->played_at,
                    'date' => substr((string) $r->played_at, 0, 10),
                    'bracket' => $r->bracket,
                    'teamKey' => md5($partners->pluck('name')->implode('|')),
                    'teamLabel' => collect($a['players'])->where('side', 'us')->pluck('spec')->implode(' / '),
                    'a' => $a,
                ];
            })
            ->filter()
            ->values();
    }

    private function row(array $g, array $xp): array
    {
        $a = $g['a'];
        $players = collect($a['players']);
        $enemy = $players->where('side', 'them')->sortByDesc('healer')->values();
        $ours = collect($a['goes'])->where('side', 'us');
        $first = $a['deaths'][0] ?? null;
        $dead = $first ? $players->firstWhere('guid', $first['who']) : null;

        return [
            'id' => $g['id'],
            'time' => substr($g['playedAt'], 11, 5),
            'won' => $a['won'],
            'level' => $this->level($players, $xp),
            'mmr' => $a['mmr'],
            'enemies' => $enemy->map(fn ($p) => ['spec' => $p['spec'], 'xp' => $this->xpCell($xp[$p['name']] ?? null)])->all(),
            'goes' => $ours->count(),
            'goesKill' => $ours->where('killLater', true)->count(),
            'defensives' => ['us' => $a['defensives']['us']['spent'], 'them' => $a['defensives']['them']['spent']],
            'firstDeath' => $first && $dead ? [
                'side' => $first['side'], 'who' => $this->short($dead['name']), 'spec' => $dead['spec'],
                'to' => $first['killingBlow']['spell'] ?? null,
            ] : null,
            'ourHealerAtDeath' => $first && $first['side'] === 'us' ? $this->healerState($first['healer']) : null,
        ];
    }

    /** Gladiator level = every player has a Gladiator season (guides-from-play.md). */
    private function level(Collection $players, array $xp): string
    {
        $cells = $players->map(fn ($p) => $xp[$p['name']] ?? null);
        if ($cells->contains(fn ($x) => $x === null)) {
            return 'pending';
        }
        $glad = $cells->filter(fn ($x) => ($x['gladSeasons'] ?? 0) > 0)->count();
        $unknown = $cells->filter(fn ($x) => ! ($x['found'] ?? false))->count();

        return match (true) {
            $glad === $players->count() => 'Gladiator',
            $glad + $unknown === $players->count() => 'Gladiator?',
            default => 'Mixed',
        };
    }

    // ------------------------------------------------------------------ who you played

    private function whoYouPlayed(Collection $won, Collection $lost, array $xp): array
    {
        $side = function (Collection $games) use ($xp) {
            $mmr = $games->map(fn ($g) => $g['a']['mmr']['them'])->filter();
            $diff = $games->map(fn ($g) => isset($g['a']['mmr']['them'], $g['a']['mmr']['us']) ? $g['a']['mmr']['them'] - $g['a']['mmr']['us'] : null)->filter(fn ($x) => $x !== null);
            $glad = $games->map(function ($g) use ($xp) {
                $e = collect($g['a']['players'])->where('side', 'them')->map(fn ($p) => $xp[$p['name']] ?? null);

                return $e->contains(fn ($x) => $x === null) ? null : $e->sum(fn ($x) => $x['gladSeasons'] ?? 0);
            })->filter(fn ($x) => $x !== null);
            $best = $games->map(function ($g) use ($xp) {
                return collect($g['a']['players'])->where('side', 'them')->map(fn ($p) => $xp[$p['name']]['exp3v3'] ?? null)->filter()->max();
            })->filter();

            return [
                'games' => $games->count(),
                'theirMmr' => $mmr->isEmpty() ? null : (int) round($mmr->avg()),
                'mmrDiff' => $diff->isEmpty() ? null : (int) round($diff->avg()),
                'theirGladSeasons' => $glad->isEmpty() ? null : round($glad->avg(), 1),
                'theirBestExp' => $best->isEmpty() ? null : (int) round($best->avg()),
            ];
        };

        return ['won' => $side($won), 'lost' => $side($lost)];
    }

    // ------------------------------------------------------------------ what differed

    private function comparisons(Collection $games, Collection $won, Collection $lost): array
    {
        $goes = fn (Collection $set, string $side = 'us') => $set->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', $side));
        $rate = fn (Collection $rows, callable $hit) => $rows->isEmpty() ? null : round(100 * $rows->filter($hit)->count() / $rows->count());
        $out = [];

        $out[] = $this->compare('Your goes that led to a kill (in the go or 30s after)',
            $rate($goes($won), fn ($g) => $g['killLater']), $rate($goes($lost), fn ($g) => $g['killLater']), '%',
            $goes($won)->count(), $goes($lost)->count(), 'goes');

        $avg = fn (Collection $rows, string $k) => $rows->isEmpty() ? null : round($rows->avg($k), 1);
        $out[] = $this->compare('Their defensives per go of yours', $avg($goes($won), 'defs'), $avg($goes($lost), 'defs'), '',
            $goes($won)->count(), $goes($lost)->count(), 'goes');
        $out[] = $this->compare('Your defensives per go of theirs', $avg($goes($won, 'them'), 'defs'), $avg($goes($lost, 'them'), 'defs'), '',
            $goes($won, 'them')->count(), $goes($lost, 'them')->count(), 'goes');

        // Drain, then kill: across every game, by how many of their defensives were already down.
        $all = $goes($games);
        $drained = $all->filter(fn ($g) => $g['drained'] >= 2);
        $fresh = $all->reject(fn ($g) => $g['drained'] >= 2);
        $out[] = $this->compare('A go led to a kill: with 2+ of their defensives already on cooldown (left) against 0-1 (right)',
            $rate($drained, fn ($g) => $g['killLater']), $rate($fresh, fn ($g) => $g['killLater']), '%',
            $drained->count(), $fresh->count(), 'goes', 'drained', 'fresh');

        // The burst on their healer's CC or a kick.
        $aligned = $all->filter(fn ($g) => $g['peak']['healerLocked'] >= 2.0 || $g['peak']['healerKicked']);
        $free = $all->reject(fn ($g) => $g['peak']['healerLocked'] >= 2.0 || $g['peak']['healerKicked']);
        $out[] = $this->compare('Your 6-second peak burst led to a kill: landing on their healer\'s CC or a kick (left) against their healer free (right)',
            $rate($aligned, fn ($g) => $g['killLater']), $rate($free, fn ($g) => $g['killLater']), '%',
            $aligned->count(), $free->count(), 'goes', 'on their CC', 'healer free');

        // Healer lockout at the moment of the first death.
        $firsts = $games->map(fn ($g) => $g['a']['deaths'][0] ?? null)->filter();
        $ourDeaths = $firsts->where('side', 'us');
        $ourKills = $firsts->where('side', 'them')->reject(fn ($d) => $d['healer']['state'] === 'was the kill');
        $out[] = $this->compare('Healer locked out at the first death: yours when we died (left), theirs when we killed (right)',
            $rate($ourDeaths, fn ($d) => $d['healer']['state'] === 'locked'), $rate($ourKills, fn ($d) => $d['healer']['state'] === 'locked'), '%',
            $ourDeaths->count(), $ourKills->count(), 'deaths', 'yours', 'theirs');

        $sum = fn (Collection $set, callable $f) => $set->sum($f);
        $outsideShare = fn (Collection $set, string $side) => ($t = $sum($set, fn ($g) => $g['a']['defensives'][$side]['spent'])) > 0
            ? round(100 * $sum($set, fn ($g) => $g['a']['defensives'][$side]['outsideTheirGoes']) / $t) : null;
        $out[] = $this->compare('Your defensives spent while they were not in a go', $outsideShare($won, 'us'), $outsideShare($lost, 'us'), '%',
            $won->count(), $lost->count(), 'games');
        $out[] = $this->compare('Their defensives spent while you were not in a go', $outsideShare($won, 'them'), $outsideShare($lost, 'them'), '%',
            $won->count(), $lost->count(), 'games');

        $perGame = fn (Collection $set) => $set->isEmpty() ? null : round($set->avg(fn ($g) => $g['a']['overlaps']['us']), 1);
        $out[] = $this->compare('Two of your defensives on one player at once, per game', $perGame($won), $perGame($lost), '',
            $won->count(), $lost->count(), 'games');

        $kicks = fn (Collection $set) => $set->flatMap(fn ($g) => collect($g['a']['kicks'])->where('side', 'us'));
        $out[] = $this->compare('Your kicks that landed inside your own go', $rate($kicks($won), fn ($k) => $k['inOwnGo']), $rate($kicks($lost), fn ($k) => $k['inOwnGo']), '%',
            $kicks($won)->count(), $kicks($lost)->count(), 'kicks');

        return array_values(array_filter($out));
    }

    private function compare(string $label, $left, $right, string $unit, int $nLeft, int $nRight, string $of, string $leftLabel = 'wins', string $rightLabel = 'losses'): ?array
    {
        if ($left === null && $right === null) {
            return null;
        }

        return [
            'label' => $label,
            'left' => ['label' => $leftLabel, 'value' => $left, 'n' => $nLeft],
            'right' => ['label' => $rightLabel, 'value' => $right, 'n' => $nRight],
            'unit' => $unit,
            'of' => $of,
            'gap' => $left !== null && $right !== null ? abs($left - $right) : 0,
            'lead' => min($nLeft, $nRight) < self::LEAD_BELOW,
        ];
    }

    // ------------------------------------------------------------------ takeaways

    /** One or two plain sentences for the uploader's own role, each with the numbers it rests on. */
    private function takeaways(array $logger, Collection $games, Collection $won, Collection $lost): array
    {
        $out = [];
        $ourDeaths = $lost->map(fn ($g) => $g['a']['deaths'][0] ?? null)->filter()->where('side', 'us');

        if ($logger['healer'] && $ourDeaths->isNotEmpty()) {
            $locked = $ourDeaths->filter(fn ($d) => $d['healer']['state'] === 'locked' || ($d['healer']['state'] === 'ended' && $d['healer']['endedAgo'] <= 1));
            $trinketGone = $locked->filter(fn ($d) => collect($d['healer']['medallionUsedAt'])->contains(fn ($t) => $t < $d['t'] - 5 && $t > $d['t'] - self::MEDALLION_COOLDOWN));
            $out[] = sprintf('When your team died, you were locked out at that moment in %d of %d losses%s.',
                $locked->count(), $ourDeaths->count(),
                $locked->isNotEmpty() ? sprintf('; your Medallion had already been used earlier in %d of those. Keep it for the CC that comes with their cooldowns', $trinketGone->count()) : '');
        }

        if ($logger['healer']) {
            $all = $games->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'us')->map(fn ($go) => $go + ['me' => collect($g['a']['players'])->firstWhere('logger', true)['guid']]));
            if ($all->isNotEmpty() && isset($all->first()['healerCcBy'])) {
                $mine = $all->filter(fn ($go) => in_array($go['me'], $go['healerCcBy'] ?? [], true));
                $inBurst = $all->filter(fn ($go) => in_array($go['me'], $go['peak']['healerCcBy'] ?? [], true));
                $rate = fn ($set) => $set->isEmpty() ? 'n/a' : round(100 * $set->where('killLater', true)->count() / $set->count()).'%';
                $out[] = sprintf('Your CC was on their healer in %d of your team\'s %d goes (%s of those led to a kill, %s of the rest), but on them during the burst itself in only %d (%s).',
                    $mine->count(), $all->count(), $rate($mine), $rate($all->diffKeys($mine)), $inBurst->count(), $rate($inBurst));
            }
        }

        if (! $logger['healer']) {
            $goes = $games->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'us'));
            $aligned = $goes->filter(fn ($g) => $g['peak']['healerLocked'] >= 2.0 || $g['peak']['healerKicked']);
            if ($goes->isNotEmpty()) {
                $out[] = sprintf('Your team\'s peak burst landed on their healer\'s CC or a kick in %d of %d goes; those led to a kill %s, the rest %s.',
                    $aligned->count(), $goes->count(),
                    $aligned->isEmpty() ? 'n/a' : round(100 * $aligned->where('killLater', true)->count() / $aligned->count()).'%',
                    $goes->count() === $aligned->count() ? 'n/a' : round(100 * $goes->diffKeys($aligned)->where('killLater', true)->count() / max(1, $goes->count() - $aligned->count())).'%');
            }
        }

        $lostGoes = $lost->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'us'));
        if ($lostGoes->isNotEmpty() && $lostGoes->where('killLater', true)->isEmpty()) {
            $out[] = sprintf('None of your %d goes in the games you lost led to a kill. Look at what they answered with, not at how hard you pushed.', $lostGoes->count());
        }

        return $out;
    }

    // ------------------------------------------------------------------ one game, drawn as goes

    /**
     * One of the player's games as its goes, both sides, in time order: each drawn like a guide
     * sequence (links with icons, the CC marked by who it landed on), with what it forced, what it
     * hit hardest with, and how it ended. Null for a round that is not the player's.
     */
    public function game(User $user, int $roundId): ?array
    {
        $g = $this->rounds($user)->firstWhere('id', $roundId);
        if (! $g || ! isset($g['a']['goes'][0]['links']) && $g['a']['goes'] !== []) {
            return $g ? ['outdated' => true] : null;
        }

        $a = $g['a'];
        $players = collect($a['players'])->keyBy('guid');
        $xp = [];
        foreach ($players as $p) {
            $xp[$p['name']] = $this->experience->cached($p['name']);
        }
        $who = fn (?string $guid) => $guid && isset($players[$guid])
            ? ['name' => $this->short($players[$guid]['name']), 'spec' => $players[$guid]['spec'], 'class' => $players[$guid]['classSlug'] ?? null, 'side' => $players[$guid]['side']]
            : null;
        $deathAt = collect($a['deaths'])->keyBy(fn ($d) => (string) $d['t']);

        $goes = collect($a['goes'])->sortBy('from')->values()->map(function ($go) use ($who, $a) {
            $killed = $go['kill'] ? $who($go['kill']) : null;
            $laterKill = ! $killed && $go['killLater']
                ? collect($a['deaths'])->first(fn ($d) => $d['side'] !== $go['side'] && $d['t'] > $go['to'])
                : null;

            return [
                'side' => $go['side'],
                'at' => $this->clock($go['from']),
                'good' => $go['good'],
                'target' => $who($go['target'] ?? null),
                'drained' => $go['drained'],
                'outcome' => $killed ? 'killed' : ($laterKill ? 'set up a kill' : 'no kill'),
                'killed' => $killed ?? ($laterKill ? $who($laterKill['who']) : null),
                'links' => $this->mergeLinks(array_map(fn ($l) => $l + ['byWho' => $who($l['by']), 'onWho' => $who($l['on'])], $go['links'])),
                'forced' => array_map(fn ($f) => $f + ['whoPlayer' => $who($f['who'])], $go['forced']),
                'burst' => array_map(fn ($b) => $b + ['whoPlayer' => $who($b['who'])], $go['burst']),
                'burstTotal' => $go['peak']['damage'],
                'burstOnHealerCc' => $go['peak']['healerLocked'] >= 2.0 || $go['peak']['healerKicked'],
            ];
        });

        $names = $goes->flatMap(fn ($go) => array_merge(array_column($go['links'], 'spell'), array_column($go['forced'], 'spell'), array_column($go['burst'], 'spell')))
            ->merge(collect($a['deaths'])->map(fn ($d) => $d['killingBlow']['spell'] ?? null))->filter()->unique()->values()->all();

        return [
            'row' => $this->row($g, $xp),
            'players' => $players->map(fn ($p) => ['name' => $this->short($p['name']), 'spec' => $p['spec'], 'class' => $p['classSlug'] ?? null, 'side' => $p['side'], 'xp' => $this->xpCell($xp[$p['name']] ?? null)])->groupBy('side')->all(),
            'goes' => $goes->all(),
            'deaths' => collect($a['deaths'])->map(fn ($d) => [
                'at' => $this->clock($d['t']), 'who' => $who($d['who']), 'side' => $d['side'],
                'blow' => $d['killingBlow'], 'healer' => $this->healerState($d['healer']),
            ])->all(),
            'icons' => $this->icons->for($names),
        ];
    }

    // ------------------------------------------------------------------ patterns, as icons

    /**
     * The patterns behind the wins and the losses, as abilities rather than percentages: what the
     * goes that killed contained, what answered the player's goes, what killed them and what they
     * killed with, where their burst came from, and what the enemy's killing goes contained.
     */
    private function patterns(Collection $games, Collection $won, Collection $lost): array
    {
        if (! isset($games->first()['a']['goes'][0]['links']) && $games->first()['a']['goes'] !== []) {
            return ['outdated' => true];
        }

        $goes = fn (Collection $set, string $side) => $set->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', $side));
        $linkKey = fn ($l) => $l['spell'].($l['cat'] === 'control' ? '|'.($l['role'] ?? 'cross') : '');
        $share = function (Collection $rows, callable $keys) {
            $count = [];
            foreach ($rows as $r) {
                foreach (array_unique($keys($r)) as $k) {
                    $count[$k] = ($count[$k] ?? 0) + 1;
                }
            }

            return collect($count)->map(fn ($n) => $rows->isEmpty() ? 0 : $n / $rows->count());
        };
        $chip = fn (string $key, $value, ?string $hint = null) => ['spell' => explode('|', $key)[0], 'role' => explode('|', $key)[1] ?? null, 'value' => $value, 'hint' => $hint];

        // 1. What your goes that killed had, against your goes that did not.
        $ours = $goes($games, 'us');
        $kill = $share($ours->where('killLater', true), fn ($g) => array_map($linkKey, $g['links']));
        $none = $share($ours->where('killLater', false), fn ($g) => array_map($linkKey, $g['links']));
        $killing = $kill->map(fn ($v, $k) => ['k' => $k, 'lift' => $v - ($none[$k] ?? 0), 'v' => $v])
            ->filter(fn ($x) => $x['v'] >= 0.25)->sortByDesc('lift')->take(6)
            ->map(fn ($x) => $chip($x['k'], round(100 * $x['v']).'%', round(100 * ($none[$x['k']] ?? 0)).'% of the rest'))->values()->all();

        // 2. What answered your goes: their defensives per go, in wins against losses.
        $answers = function (Collection $set) use ($goes) {
            $rows = $goes($set, 'us');
            $count = [];
            foreach ($rows as $g) {
                foreach ($g['forced'] as $f) {
                    $count[$f['spell']] = ($count[$f['spell']] ?? 0) + 1;
                }
            }
            arsort($count);

            return ['goes' => $rows->count(), 'top' => array_slice($count, 0, 6, true)];
        };
        $answerChips = fn (array $a) => collect($a['top'])->map(fn ($n, $spell) => ['spell' => $spell, 'role' => null, 'value' => $n.'×', 'hint' => round($n / max(1, $a['goes']), 1).' per go'])->values()->all();
        [$aw, $al] = [$answers($won), $answers($lost)];

        // 3. What killed you, and what you killed with.
        $blows = function (Collection $set, string $side) {
            return $set->map(fn ($g) => collect($g['a']['deaths'])->firstWhere('side', $side)['killingBlow']['spell'] ?? null)
                ->filter()->countBy()->sortDesc()->take(6)->map(fn ($n, $spell) => ['spell' => $spell, 'role' => null, 'value' => $n.'×', 'hint' => null])->values()->all();
        };

        // 4. Where your burst came from.
        $burst = [];
        foreach ($ours as $g) {
            foreach ($g['burst'] as $b) {
                $burst[$b['spell']] = ($burst[$b['spell']] ?? 0) + $b['amount'];
            }
        }
        arsort($burst);
        $burstTotal = max(1, array_sum($burst));

        // 5. What the enemy's goes that killed you had.
        $theirs = $goes($lost, 'them')->where('killLater', true);
        $theirKill = $share($theirs, fn ($g) => array_map($linkKey, $g['links']))->sortDesc()->take(6)
            ->map(fn ($v, $k) => $chip($k, round(100 * $v).'%'))->values()->all();

        $out = [
            ['title' => 'Your goes that killed', 'note' => 'What your goes that led to a kill contained, most distinctive first. The small number is how often the rest of your goes had it.',
                'columns' => [['label' => $ours->where('killLater', true)->count().' goes that killed', 'chips' => $killing]]],
            ['title' => 'What answered your goes', 'note' => 'Their defensives inside your goes. In the games you lost they had more to spend, or spent it better.',
                'columns' => [['label' => 'In wins ('.$aw['goes'].' goes)', 'chips' => $answerChips($aw)], ['label' => 'In losses ('.$al['goes'].' goes)', 'chips' => $answerChips($al)]]],
            ['title' => 'How the games ended', 'note' => 'The killing blow of the first death.',
                'columns' => [['label' => 'You killed with', 'chips' => $blows($won, 'them')], ['label' => 'You died to', 'chips' => $blows($lost, 'us')]]],
            ['title' => 'Where your burst came from', 'note' => 'The abilities in your 6-second peaks, by share of that damage.',
                'columns' => [['label' => 'Your peaks', 'chips' => collect(array_slice($burst, 0, 8, true))->map(fn ($v, $spell) => ['spell' => $spell, 'role' => null, 'value' => round(100 * $v / $burstTotal).'%', 'hint' => null])->values()->all()]]],
            ['title' => 'The goes that beat you', 'note' => 'What their goes contained when they led to a kill on you.',
                'columns' => [['label' => $theirs->count().' of their goes that killed', 'chips' => $theirKill]]],
        ];

        $names = collect($out)->flatMap(fn ($p) => collect($p['columns'])->flatMap(fn ($c) => array_column($c['chips'], 'spell')))->unique()->values()->all();

        return ['sections' => $out, 'icons' => $this->icons->for($names)];
    }

    /**
     * One area CC is one step, not one per player it hit: a Leg Sweep that stunned two players
     * logged two auras. Same spell, same caster, within half a second: one link, labelled by the
     * most important player it landed on (their healer, then the target), with how many others.
     */
    private function mergeLinks(array $links): array
    {
        $rank = ['healer' => 0, 'target' => 1, 'cross' => 2];
        $out = [];
        foreach ($links as $l) {
            $last = $out ? count($out) - 1 : null;
            if ($last !== null && $l['cat'] === 'control' && $out[$last]['cat'] === 'control'
                && $out[$last]['spell'] === $l['spell'] && $out[$last]['by'] === $l['by'] && abs($l['t'] - $out[$last]['t']) <= 0.5) {
                $out[$last]['alsoHit'] = ($out[$last]['alsoHit'] ?? 0) + 1;
                if (($rank[$l['role']] ?? 3) < ($rank[$out[$last]['role']] ?? 3)) {
                    $out[$last]['role'] = $l['role'];
                    $out[$last]['onWho'] = $l['onWho'];
                }

                continue;
            }
            $out[] = $l + ['alsoHit' => 0];
        }

        return $out;
    }

    // ------------------------------------------------------------------ where the losses came from

    /** Gladiator's Medallion's cooldown, from the spell data. */
    private const MEDALLION_COOLDOWN = 120;

    /** How much each kind of mistake counts. Shown on the page; changing one changes every split. */
    public const FAULT_WEIGHTS = [
        'locked_trinket_used' => 2,
        'locked_trinket_unused' => 1,
        'overlap' => 1,
        'defensive_outside' => 1,
        'burst_healer_free' => 1,
        'them_experience' => 2,
        'them_mmr' => 1,
        'them_answered' => 1,
    ];

    /**
     * A rough split of the losses: every mistake the log can pin on a button, owned by the player
     * who pressed it, plus what the other team did that was nobody's fault on ours. Weighted by
     * FAULT_WEIGHTS and turned into shares. AN ESTIMATE FROM RULES, NOT A VERDICT: the log cannot see
     * positioning, calls, or a mistake nobody pressed a button for, and the page says so.
     */
    private function faults(Collection $lost, array $xp): array
    {
        if ($lost->isEmpty() || ! isset($lost->first()['a']['overlaps']['rows'])) {
            return $lost->isEmpty() ? [] : ['outdated' => true];
        }

        $owners = [];
        $games = [];

        foreach ($lost as $g) {
            $items = $this->lossItems($g['a'], $xp);

            foreach ($items as $item) {
                $owners[$item['owner']] ??= ['weight' => 0, 'class' => $item['class'], 'ours' => ! str_starts_with($item['owner'], 'Them')];
                $owners[$item['owner']]['weight'] += $item['weight'];
            }

            $games[] = ['time' => substr($g['playedAt'], 11, 5), 'items' => $items];
        }

        $total = max(1, array_sum(array_column($owners, 'weight')));
        uasort($owners, fn ($x, $y) => $y['weight'] <=> $x['weight']);
        $spells = collect($games)->flatMap(fn ($g) => array_column($g['items'], 'spell'))->filter()->unique()->values()->all();

        return [
            'shares' => collect($owners)->map(fn ($o, $name) => ['owner' => $name, 'class' => $o['class'], 'ours' => $o['ours'], 'share' => (int) round(100 * $o['weight'] / $total), 'weight' => $o['weight']])->values()->all(),
            'games' => $games,
            'weights' => self::FAULT_WEIGHTS,
            'icons' => $this->icons->for($spells),
        ];
    }

    /**
     * The rules behind faults(), for ONE lost round: each mistake the log can pin on a button, owned
     * by the player who pressed it, and what the other team brought. Public so a single game's card
     * (GameCardService) reads exactly the same rules as the page's split. Needs an analysis of
     * version 3 or later (`overlaps.rows`); an older one yields nothing.
     *
     * @param  array  $a  one round's `payload['analysis']`
     * @param  array<string, array|null>  $xp  cached experience by full name
     * @return array<int, array{owner: string, class: ?string, weight: int, text: string, spell: ?string}>
     */
    public function lossItems(array $a, array $xp): array
    {
        if (! isset($a['overlaps']['rows'])) {
            return [];
        }

        $w = self::FAULT_WEIGHTS;
        $players = collect($a['players'])->keyBy('guid');
        $label = fn ($guid) => isset($players[$guid]) ? $players[$guid]['spec'] : 'Your team';
        $isOurs = fn ($guid) => isset($players[$guid]) && $players[$guid]['side'] === 'us';
        $ourHealer = collect($a['players'])->first(fn ($p) => $p['side'] === 'us' && $p['healer']);
        $items = [];
        $add = function (string $owner, ?string $class, string $kind, string $text, ?string $spell = null) use (&$items, $w) {
            $items[] = ['owner' => $owner, 'class' => $class, 'weight' => $w[$kind], 'text' => $text, 'spell' => $spell];
        };

        // The healer at the moment our player died.
        $death = collect($a['deaths'])->firstWhere('side', 'us');
        if ($death && $ourHealer && ($death['healer']['state'] === 'locked' || ($death['healer']['state'] === 'ended' && $death['healer']['endedAgo'] <= 1))) {
            // "Already used" only while it was still on cooldown: 120s is Gladiator's Medallion's
            // cooldown in the spell data. One used longer ago than that was back, and unused.
            $used = collect($death['healer']['medallionUsedAt'])->filter(fn ($t) => $t < $death['t'] - 5 && $t > $death['t'] - self::MEDALLION_COOLDOWN)->max();
            $dead = $label($death['who']);
            $used !== null
                ? $add($ourHealer['spec'], $ourHealer['classSlug'] ?? null, 'locked_trinket_used', sprintf('Locked out when your %s died; Medallion used %ds earlier', $dead, round($death['t'] - $used)), "Gladiator's Medallion")
                : $add($ourHealer['spec'], $ourHealer['classSlug'] ?? null, 'locked_trinket_unused', sprintf('Locked out when your %s died, with the Medallion unused', $dead), "Gladiator's Medallion");
        }

        // A defensive stacked on one that was already up: the second one's owner.
        foreach ($a['overlaps']['rows'] as $o) {
            if ($o['side'] === 'us' && $isOurs($o['second']['by'])) {
                $add($label($o['second']['by']), $players[$o['second']['by']]['classSlug'] ?? null, 'overlap',
                    sprintf('%s on your %s while %s was up (%ss together)', $o['second']['spell'], $label($o['on']), $o['first']['spell'], $o['seconds']), $o['second']['spell']);
            }
        }

        // A defensive spent while they were not in a go.
        foreach ($a['defensives']['us']['rows'] as $d) {
            if ($d['outside'] && $isOurs($d['who'])) {
                $add($label($d['who']), $players[$d['who']]['classSlug'] ?? null, 'defensive_outside', sprintf('%s while they were not in a go', $d['spell']), $d['spell']);
            }
        }

        // A burst that landed with their healer free.
        foreach (collect($a['goes'])->where('side', 'us') as $go) {
            if ($go['peak']['healerLocked'] < 2.0 && ! $go['peak']['healerKicked']) {
                $add('Your team (burst timing)', null, 'burst_healer_free', 'Your hardest 6 seconds landed with their healer free');
            }
        }

        // What the other team brought.
        $gladOf = fn (string $side) => collect($a['players'])->where('side', $side)->sum(fn ($p) => $xp[$p['name']]['gladSeasons'] ?? 0);
        if ($gladOf('them') >= $gladOf('us') + 3) {
            $add('Them: more experienced', null, 'them_experience', sprintf('%d Gladiator seasons between them, %d between you', $gladOf('them'), $gladOf('us')));
        }
        if (($a['mmr']['them'] ?? 0) - ($a['mmr']['us'] ?? 0) >= 50) {
            $add('Them: higher MMR', null, 'them_mmr', sprintf('%d MMR above yours', $a['mmr']['them'] - $a['mmr']['us']));
        }
        $ours = collect($a['goes'])->where('side', 'us');
        if ($ours->isNotEmpty() && $ours->where('killLater', true)->isEmpty() && $ours->avg('defs') >= 2) {
            $add('Them: answered every go', null, 'them_answered', sprintf('Your %d goes forced %.1f defensives each and none led to a kill', $ours->count(), $ours->avg('defs')));
        }

        return $items;
    }

    private function clock(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }

    // ------------------------------------------------------------------ formatting

    private function xpCell(?array $x): string
    {
        return match (true) {
            $x === null => 'looking up…',
            ! ($x['found'] ?? false) => 'no profile',
            ($x['gladSeasons'] ?? 0) > 0 => $x['gladSeasons'].'x Glad '.($x['exp3v3'] ?? '?'),
            default => ($x['bestRank'] ?? 'no rank').' '.($x['exp3v3'] ?? '?'),
        };
    }

    private function healerState(array $h): string
    {
        return match ($h['state']) {
            'locked' => 'locked out',
            'ended' => 'lockout ended '.$h['endedAgo'].'s before',
            'was the kill' => 'was the kill',
            default => 'free',
        };
    }

    private function short(string $name): string
    {
        return explode('-', $name)[0];
    }
}
