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

    public function __construct(private PlayerExperienceService $experience) {}

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
            $trinketGone = $locked->filter(fn ($d) => collect($d['healer']['medallionUsedAt'])->contains(fn ($t) => $t < $d['t'] - 5));
            $out[] = sprintf('When your team died, you were locked out at that moment in %d of %d losses%s.',
                $locked->count(), $ourDeaths->count(),
                $locked->isNotEmpty() ? sprintf('; your Medallion had already been used earlier in %d of those. Keep it for the CC that comes with their cooldowns', $trinketGone->count()) : '');
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
