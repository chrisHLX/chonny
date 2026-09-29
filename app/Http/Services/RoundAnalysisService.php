<?php

namespace App\Http\Services;

/**
 * One arena round, measured the way match-review-operations.md defines, for any player.
 *
 * Run at upload, while the raw log is still in hand: an uploaded round's text is discarded straight
 * after it is derived, so anything not measured HERE can never be recovered without the player
 * uploading the game again. What it returns is stored in `arena_rounds.payload['analysis']` and
 * combined across games by MatchAnalysisService.
 *
 * THIS IS THE PRODUCT DEFINITION of the measures `tools/match-review/killread.php` was built to
 * explore (2026-09-28): the same go, drain, kill read, peak burst, timing, overcommitment, overlap
 * and kick measures, with the definitions that research settled. The research script can keep
 * changing; this is what a player's page is built from, so a change here is a change to every
 * player's analysis and needs a test.
 *
 * "US" IS THE LOGGING PLAYER'S SIDE: `reaction` as the log's writer saw it, never an arena team id
 * (CLAUDE.md rule 12). So it works for whoever uploads, with no names anywhere in the code.
 */
class RoundAnalysisService
{
    /**
     * Bumped when a measure's definition or the stored shape changes, so stored analyses can be told
     * apart. 2 (2026-09-29): each go keeps its links, the defensives it forced and its burst as
     * structured rows (who, what, when, on whom), so a go can be drawn with icons like a guide.
     */
    public const VERSION = 2;

    /** Crowd control that takes a player out: a slow or root does not stop a healer healing. */
    private const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

    /** A link within this long of the chain's end makes a good go. */
    private const TIGHT_LINK = 5.0;

    /** A link this far still joins, but only through an offensive cooldown: a bad go. */
    private const LOOSE_LINK = 10.0;

    /** An offensive cast counts as "ending" this long after it is pressed, for linking. */
    private const OFFENSIVE_HOLD = 5.0;

    /** A go's window runs this long past its last offensive cooldown. */
    private const GO_TAIL = 15.0;

    /** A go "converts" if a kill lands in its window or this long after it. */
    private const KILL_LATER = 30.0;

    /** The burst window: the 6s of a go with the most damage from its DPS. */
    private const PEAK = 6.0;

    private const DAMAGE_EVENTS = ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED'];

    public function __construct(private ArenaMomentService $moments, private ArenaLogService $arena) {}

    /**
     * @param  array<int, string>  $lines  one round's raw log lines
     * @param  array  $metadata  that round's derived metadata
     */
    public function analyse(array $lines, array $metadata): ?array
    {
        $roster = $this->moments->roster($metadata);

        if (count($roster) < 2) {
            return null;
        }

        // The logging player: affiliation 1 in the metadata. Their `reaction` is our side.
        $logger = collect($metadata['units'] ?? [])->first(fn ($u) => (int) ($u['affiliation'] ?? 0) === 1 && isset($roster[$u['id']]));
        $us = $logger ? $roster[$logger['id']]['side'] : 1;
        $sideOf = fn (?string $guid) => $guid !== null && isset($roster[$guid]) ? ($roster[$guid]['side'] === $us ? 'us' : 'them') : null;

        $tl = $this->moments->readTimeline($lines, $roster);
        $ccMap = $this->moments->crowdControl();

        foreach ($tl['commitments'] as &$c) {
            $c['cat'] = in_array($c['kind'], ['offensive', 'mixed', 'defensive'], true) ? $c['kind']
                : (isset($ccMap[$c['spellId']]) ? 'control' : 'utility');
        }
        unset($c);

        [$dmg, $owner, $buffs, $interrupts, $end] = $this->secondPass($lines, $roster);
        $credit = function (string $src) use ($roster, $owner) {
            for ($i = 0; $i < 3 && ! isset($roster[$src]) && isset($owner[$src]); $i++) {
                $src = $owner[$src];
            }

            return $src;
        };

        $healers = [];
        foreach ($roster as $g => $r) {
            if ($r['healer']) {
                $healers[$sideOf($g)] = $g;
            }
        }

        // Lockout per player, merged so a stun under a silence counts once.
        $locked = [];
        foreach ($roster as $g => $r) {
            $locked[$g] = $this->union(array_map(fn ($c) => [$c['from'], $c['to']],
                array_filter($tl['control'], fn ($c) => $c['on'] === $g && in_array($c['dr'], self::LOCKOUT, true))));
        }

        $deaths = array_values(array_map(fn ($d) => $d + ['side' => $sideOf($d['who'])], $tl['deaths']));
        $firstDeath = $deaths[0]['t'] ?? INF;
        $goes = $this->goes($tl, $roster, $sideOf, $credit, $healers, $dmg);

        $goRows = [];
        foreach ($goes as $side => $list) {
            $other = $side === 'us' ? 'them' : 'us';
            foreach ($list as $go) {
                $goRows[] = $this->describeGo($go, $side, $other, $tl, $deaths, $locked, $healers, $roster, $sideOf, $credit, $dmg, $interrupts, $goes);
            }
        }

        return [
            'version' => self::VERSION,
            'won' => (int) ($metadata['result'] ?? 0) === 3,
            'mmr' => $this->mmr($end, $metadata),
            'players' => $this->players($metadata, $roster, $sideOf, $logger['id'] ?? null),
            'goes' => $goRows,
            'deaths' => array_map(fn ($d) => $this->killRead($d, $tl, $dmg, $locked, $healers, $roster, $sideOf, $credit, $goes), $deaths),
            'defensives' => $this->defensives($tl, $sideOf, $goes, $firstDeath, $roster),
            'overlaps' => $this->overlaps($tl, $buffs, $sideOf, $firstDeath),
            'kicks' => $this->kicks($interrupts, $sideOf, $credit, $healers, $goes),
            'lockout' => array_map(fn ($iv) => round($this->len($iv), 1), $locked),
        ];
    }

    // ------------------------------------------------------------------ reading

    /** Damage with spell names, pet owners, buff auras and interrupts, on readTimeline's clock. */
    private function secondPass(array $lines, array $roster): array
    {
        $t0 = null;
        $dmg = [];
        $owner = [];
        $buffs = [];
        $open = [];
        $interrupts = [];
        $end = null;

        foreach ($lines as $line) {
            $s = $this->seconds($line);
            if ($s === null) {
                continue;
            }
            $t0 ??= $s;
            $t = round($s - $t0, 2);
            $body = explode('  ', $line, 2)[1] ?? '';
            $event = strtok($body, ',');

            if ($event === 'ARENA_MATCH_END') {
                $end = explode(',', $body);

                continue;
            }
            if ($event === 'SPELL_SUMMON') {
                $f = str_getcsv($body);
                $owner[$f[5]] = $f[1];

                continue;
            }
            if ($event === 'SPELL_CAST_SUCCESS') {
                $f = str_getcsv($body);
                if (! str_starts_with($f[1], 'Player-') && str_starts_with($f[13] ?? '', 'Player-')) {
                    $owner[$f[1]] ??= $f[13];
                }

                continue;
            }
            if ($event === 'SPELL_INTERRUPT') {
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $interrupts[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10]];
                }

                continue;
            }
            if (($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') && str_contains($body, ',BUFF')) {
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $k = $f[5].'|'.$f[10];
                    if ($event === 'SPELL_AURA_APPLIED') {
                        $open[$k] = $t;
                    } elseif (isset($open[$k])) {
                        $buffs[] = ['name' => $f[10], 'on' => $f[5], 'from' => $open[$k], 'to' => $t];
                        unset($open[$k]);
                    }
                }

                continue;
            }
            if (! in_array($event, self::DAMAGE_EVENTS, true)) {
                continue;
            }
            $f = str_getcsv($body);
            $swing = str_starts_with($event, 'SWING');
            $amount = $f[count($f) - ($swing ? 10 : 11)] ?? null;
            if (! is_numeric($amount) || ! isset($roster[$f[5]]) || $f[1] === $f[5]) {
                continue;
            }
            $base = $swing ? 9 : 12;
            $cur = $f[$base + 2] ?? null;
            $max = $f[$base + 3] ?? null;
            $dmg[] = [
                't' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount,
                'spell' => $swing ? 'Melee' : $f[10],
                'hpAfter' => is_numeric($cur) && is_numeric($max) && $max > 0 ? round(100 * $cur / $max, 1) : null,
            ];
        }

        return [$dmg, $owner, $buffs, $interrupts, $end];
    }

    // ------------------------------------------------------------------ goes

    /**
     * Goes as chains (match-review-operations.md, "What a go is"): offensive casts and CC that
     * landed on the enemy, linked from the chain's END; 5s links make a good go, links up to 10s
     * join only through an offensive cooldown and make a bad go; no offensive cooldown, no go.
     */
    private function goes(array $tl, array $roster, callable $sideOf, callable $credit, array $healers, array $dmg): array
    {
        $goes = ['us' => [], 'them' => []];

        foreach (['us', 'them'] as $side) {
            $other = $side === 'us' ? 'them' : 'us';
            $events = [];
            foreach ($tl['commitments'] as $c) {
                if ($sideOf($c['who']) === $side && in_array($c['cat'], ['offensive', 'mixed'], true)) {
                    $events[] = ['t' => $c['t'], 'end' => $c['t'] + self::OFFENSIVE_HOLD, 'spell' => $c['spell'], 'cat' => 'offensive', 'on' => null, 'by' => $c['who']];
                }
            }
            foreach ($tl['control'] as $c) {
                if (in_array($c['dr'], self::LOCKOUT, true) && $sideOf($c['on']) === $other && $sideOf($credit($c['by'])) === $side) {
                    $events[] = ['t' => $c['from'], 'end' => $c['to'], 'spell' => $c['spell'], 'cat' => 'control', 'on' => $c['on'], 'by' => $credit($c['by'])];
                }
            }
            usort($events, fn ($a, $b) => $a['t'] <=> $b['t']);

            $chains = [];
            $cur = [];
            $end = -INF;
            $endByOffensive = false;
            foreach ($events as $e) {
                $gap = $e['t'] - $end;
                $loose = $e['cat'] === 'offensive' || $endByOffensive;
                if ($cur && ($gap > self::LOOSE_LINK || ($gap > self::TIGHT_LINK && ! $loose))) {
                    $chains[] = $cur;
                    $cur = [];
                    $end = -INF;
                    $endByOffensive = false;
                }
                $e['gap'] = $cur ? round(max(0, $e['t'] - $end), 1) : 0.0;
                $cur[] = $e;
                if ($e['end'] >= $end) {
                    $end = $e['end'];
                    $endByOffensive = $e['cat'] === 'offensive';
                }
            }
            if ($cur) {
                $chains[] = $cur;
            }

            foreach ($chains as $ch) {
                $offs = array_values(array_filter($ch, fn ($e) => $e['cat'] === 'offensive'));
                if (! $offs) {
                    continue;
                }
                $from = $ch[0]['t'];
                $to = max(end($offs)['t'] + self::GO_TAIL, max(array_column($ch, 'end')));
                $hit = [];
                foreach ($dmg as $x) {
                    if ($x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other && $sideOf($credit($x['src'])) === $side) {
                        $hit[$x['dst']] = ($hit[$x['dst']] ?? 0) + $x['amount'];
                    }
                }
                arsort($hit);
                $target = array_key_first($hit);
                foreach ($ch as &$e) {
                    if ($e['cat'] === 'control') {
                        $e['role'] = ($healers[$other] ?? null) === $e['on'] ? 'healer' : ($e['on'] === $target ? 'target' : 'cross');
                    }
                }
                unset($e);
                $goes[$side][] = ['from' => $from, 'to' => $to, 'casts' => $ch, 'target' => $target,
                    'firstOffensive' => $offs[0]['t'], 'maxGap' => max(array_column($ch, 'gap'))];
            }
        }

        return $goes;
    }

    private function describeGo(array $go, string $side, string $other, array $tl, array $deaths, array $locked, array $healers,
        array $roster, callable $sideOf, callable $credit, array $dmg, array $interrupts, array $goes): array
    {
        [$from, $to] = [$go['from'], $go['to']];
        $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other && $c['cat'] === 'defensive' && $c['t'] >= $from && $c['t'] <= $to));
        $drained = count(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other && $c['cat'] === 'defensive' && $c['t'] < $from && $from < $c['t'] + $c['cooldown']));
        $kill = collect($deaths)->first(fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to);
        $killLater = collect($deaths)->contains(fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to + self::KILL_LATER);

        // Peak burst: the 6s of this go with the most damage from the attacking DPS.
        $hits = array_values(array_filter($dmg, function ($x) use ($credit, $sideOf, $roster, $side, $other, $from, $to) {
            $who = $credit($x['src']);

            return $x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other && $sideOf($who) === $side && isset($roster[$who]) && ! $roster[$who]['healer'];
        }));
        $peak = ['sum' => 0, 'from' => $from];
        for ($i = 0, $j = 0, $run = 0; $i < count($hits); $i++) {
            $run += $hits[$i]['amount'];
            while ($hits[$i]['t'] - $hits[$j]['t'] > self::PEAK) {
                $run -= $hits[$j]['amount'];
                $j++;
            }
            if ($run > $peak['sum']) {
                $peak = ['sum' => $run, 'from' => $hits[$j]['t']];
            }
        }
        $byAbility = [];
        $byPlayer = [];
        $burstRows = [];
        foreach ($hits as $x) {
            if ($x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + self::PEAK) {
                $who = $credit($x['src']);
                $byAbility[$roster[$who]['name'].': '.$x['spell']] = ($byAbility[$roster[$who]['name'].': '.$x['spell']] ?? 0) + $x['amount'];
                $byPlayer[$who] = ($byPlayer[$who] ?? 0) + $x['amount'];
                $k = $who.'|'.$x['spell'];
                $burstRows[$k] ??= ['who' => $who, 'spell' => $x['spell'], 'amount' => 0];
                $burstRows[$k]['amount'] += $x['amount'];
            }
        }
        arsort($byAbility);
        usort($burstRows, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $defHealer = $healers[$other] ?? null;
        $atkHealer = $healers[$side] ?? null;
        $peakWin = [[$peak['from'], $peak['from'] + self::PEAK]];
        $peakKicks = array_filter($interrupts, fn ($x) => $x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + self::PEAK && $sideOf($credit($x['src'])) === $side && $x['dst'] === $defHealer);
        $burst = [[$go['firstOffensive'] - 1, $go['firstOffensive'] + 4]];

        return [
            'side' => $side,
            'from' => $from,
            'to' => round($to, 2),
            'good' => $go['maxGap'] <= self::TIGHT_LINK,
            'chain' => implode(', ', array_map(fn ($e) => $e['spell'].(isset($e['role']) ? ' > '.$e['role'] : ''), $go['casts'])),
            // The go as rows, for drawing it like a guide sequence: each link in order, who pressed
            // or applied it, on whom, seconds from the go's start, and the gap that joined it.
            'links' => array_map(fn ($e) => [
                't' => round($e['t'] - $from, 1), 'spell' => $e['spell'], 'cat' => $e['cat'],
                'role' => $e['role'] ?? null, 'by' => $e['by'] ?? null, 'on' => $e['on'], 'gap' => $e['gap'],
            ], $go['casts']),
            'forced' => array_map(fn ($c) => ['t' => round($c['t'] - $from, 1), 'spell' => $c['spell'], 'who' => $c['who']], $defs),
            'burst' => array_slice($burstRows, 0, 6),
            'target' => $go['target'],
            'healerCc' => count(array_filter($go['casts'], fn ($e) => ($e['role'] ?? null) === 'healer')),
            'defs' => count($defs),
            'defNames' => array_column($defs, 'spell'),
            'drained' => $drained,
            'kill' => $kill ? $kill['who'] : null,
            'killLater' => $killLater,
            'peak' => [
                'damage' => $peak['sum'],
                'joint' => count($byPlayer) >= 2 && min($byPlayer) / max(1, array_sum($byPlayer)) >= 0.25,
                'healerLocked' => $defHealer ? round($this->cross($locked[$defHealer], $peakWin), 1) : 0.0,
                'healerKicked' => count($peakKicks) > 0,
                'abilities' => array_slice($byAbility, 0, 5, true),
            ],
            'ownHealerLockedAtCds' => $atkHealer ? $this->cross($locked[$atkHealer], $burst) > 0 : false,
        ];
    }

    // ------------------------------------------------------------------ deaths

    private function killRead(array $d, array $tl, array $dmg, array $locked, array $healers, array $roster, callable $sideOf, callable $credit, array $goes): array
    {
        $killer = $d['side'] === 'us' ? 'them' : 'us';
        $win = array_values(array_filter($dmg, fn ($x) => $x['dst'] === $d['who'] && $x['t'] <= $d['t'] + 0.05 && $x['t'] >= $d['t'] - 10));
        $shares = [];
        foreach ($win as $x) {
            $who = $credit($x['src']);
            $key = isset($roster[$who]) ? $roster[$who]['name'] : 'pets/other';
            $shares[$key] = ($shares[$key] ?? 0) + $x['amount'];
        }
        arsort($shares);
        $total = max(1, array_sum($shares));
        $kb = end($win) ?: null;
        $before = null;
        foreach ($win as $x) {
            if ($x === $kb) {
                break;
            }
            $before = $x['hpAfter'] ?? $before;
        }

        $healer = $healers[$d['side']] ?? null;
        $state = 'none';
        $endedAgo = null;
        if ($healer === $d['who']) {
            $state = 'was the kill';
        } elseif ($healer) {
            foreach ($locked[$healer] as [$a, $b]) {
                if ($a <= $d['t'] && $b >= $d['t'] - 0.1) {
                    $state = 'locked';
                } elseif ($b < $d['t'] && $b >= $d['t'] - 10 && $state === 'none') {
                    $state = 'ended';
                    $endedAgo = round($d['t'] - $b, 1);
                }
            }
        }

        $go = collect($goes[$killer])->first(fn ($g) => $g['from'] <= $d['t'] && $g['to'] >= $d['t'] - 0.1);
        $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $d['side'] && $c['cat'] === 'defensive' && $c['t'] >= $d['t'] - 30 && $c['t'] <= $d['t']));
        $medallion = $healer ? array_values(array_map(fn ($c) => $c['t'], array_filter($tl['commitments'], fn ($c) => $c['who'] === $healer && $c['spell'] === "Gladiator's Medallion" && $c['t'] <= $d['t']))) : [];

        return [
            't' => $d['t'],
            'who' => $d['who'],
            'side' => $d['side'],
            'killingBlow' => $kb ? ['spell' => $kb['spell'], 'amount' => $kb['amount'], 'hpBefore' => $before] : null,
            'shares' => array_map(fn ($v) => round(100 * $v / $total), array_slice($shares, 0, 4, true)),
            'healer' => ['state' => $state, 'endedAgo' => $endedAgo, 'medallionUsedAt' => $medallion],
            'goStartedAgo' => $go ? round($d['t'] - $go['from'], 1) : null,
            'defensives30s' => array_map(fn ($c) => ['spell' => $c['spell'], 'who' => $roster[$c['who']]['name'] ?? '?', 'ago' => round($d['t'] - $c['t'])], $defs),
        ];
    }

    // ------------------------------------------------------------------ the rest

    /** Defensives each side spent before the first death, and how many outside the other side's goes. */
    private function defensives(array $tl, callable $sideOf, array $goes, float $firstDeath, array $roster): array
    {
        $out = [];
        foreach (['us' => 'them', 'them' => 'us'] as $side => $opp) {
            $spent = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $side && $c['cat'] === 'defensive' && $c['t'] <= $firstDeath));
            $outside = array_filter($spent, fn ($c) => ! collect($goes[$opp])->contains(fn ($g) => $c['t'] >= $g['from'] && $c['t'] <= $g['to']));
            $out[$side] = ['spent' => count($spent), 'outsideTheirGoes' => count($outside)];
        }

        return $out;
    }

    /** Two different defensives on one player at once for a second or more, before the first death. */
    private function overlaps(array $tl, array $buffs, callable $sideOf, float $firstDeath): array
    {
        $names = array_unique(array_column(array_filter($tl['commitments'], fn ($c) => $c['cat'] === 'defensive'), 'spell'));
        $db = array_values(array_filter($buffs, fn ($b) => in_array($b['name'], $names, true)));
        $out = ['us' => 0, 'them' => 0];
        for ($i = 0; $i < count($db); $i++) {
            for ($j = $i + 1; $j < count($db); $j++) {
                if ($db[$i]['on'] === $db[$j]['on'] && $db[$i]['name'] !== $db[$j]['name'] && $db[$i]['from'] <= $firstDeath
                    && min($db[$i]['to'], $db[$j]['to']) - max($db[$i]['from'], $db[$j]['from']) >= 1.0) {
                    $out[$sideOf($db[$i]['on'])]++;
                }
            }
        }

        return $out;
    }

    private function kicks(array $interrupts, callable $sideOf, callable $credit, array $healers, array $goes): array
    {
        $out = [];
        foreach ($interrupts as $x) {
            $side = $sideOf($credit($x['src']));
            if ($side === null) {
                continue;
            }
            $out[] = [
                'side' => $side,
                'onHealer' => ($healers[$sideOf($x['dst'])] ?? null) === $x['dst'],
                'inOwnGo' => collect($goes[$side])->contains(fn ($g) => $x['t'] >= $g['from'] && $x['t'] <= $g['to']),
            ];
        }

        return $out;
    }

    /** Both teams' MMR from ARENA_MATCH_END (fields 3 and 4); ours is the one the metadata recorded. */
    private function mmr(?array $end, array $metadata): array
    {
        $ours = $metadata['playerTeamRating'] ?? null;
        if ($end === null || $ours === null || ! isset($end[3], $end[4])) {
            return ['us' => $ours, 'them' => null];
        }

        return ['us' => (int) $ours, 'them' => (int) $end[3] === (int) $ours ? (int) $end[4] : (int) $end[3]];
    }

    private function players(array $metadata, array $roster, callable $sideOf, ?string $logger): array
    {
        $out = [];
        foreach ($metadata['units'] ?? [] as $u) {
            if (! isset($roster[$u['id']])) {
                continue;
            }
            $spec = $this->arena->specForExternalId((string) $u['spec']);
            $out[] = [
                'guid' => $u['id'],
                'name' => $u['name'],
                'spec' => trim(($spec?->name ?? '?').' '.($spec?->gameClass?->name ?? '')),
                'specExternalId' => (int) $u['spec'],
                'classSlug' => $spec?->gameClass?->slug,
                'side' => $sideOf($u['id']),
                'healer' => $roster[$u['id']]['healer'],
                'logger' => $u['id'] === $logger,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ intervals

    private function union(array $iv): array
    {
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($iv as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    private function len(array $iv): float
    {
        return array_sum(array_map(fn ($x) => $x[1] - $x[0], $iv));
    }

    private function cross(array $a, array $b): float
    {
        $s = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s += max(0, min($x[1], $y[1]) - max($x[0], $y[0]));
            }
        }

        return $s;
    }

    /** Same clock as ArenaMomentService::seconds(): only differences matter. */
    private function seconds(string $line): ?float
    {
        if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
            return null;
        }

        return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
    }
}
