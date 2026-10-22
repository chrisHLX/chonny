<?php

namespace App\Http\Services;

/**
 * Moments (2026-10-17): situations in a round that a player would recognise, each with what was up,
 * what was pressed and what it cost, read from the round's stored timeline (RoundAnalysisService
 * version 16). The measures say how much; a moment says when, and what happened around it.
 *
 * Three kinds to start, each from Chriso's games:
 *  - control through a defensive: crowd control landing on a player while one of their own big
 *    defensives was up (15 Oct: their Priest's Mind Control on our Warrior through Pain Suppression
 *    and Die by the Sword spent 3.4s of both). How it ended, and what the player's healer pressed
 *    meanwhile. Both teams: when it is ours on them, it is a play to repeat.
 *  - control in their go: during the other team's go, crowd control on a teammate that the player's
 *    spec is seen dispelling, while the player was free to act. Whether and how fast it went, and
 *    what was pressed instead. The log cannot say whether a dispel was the better global then
 *    (match-review.md, reading rule 8); the moment says what happened and leaves the judgement.
 *    Damage over time is left out: it is rarely what a global in a go is for, and it buried the rest.
 *  - the start of their go, for a healer: the first casts after the go began, the first big
 *    defensive and when, the target's health from the start to its lowest, and whether it killed.
 *
 * A big defensive is one the classification lists with a cooldown of BIG_COOLDOWN or more, not a
 * trinket or racial, and not cast on several players at once (Rallying Cry, Anti-Magic Zone): a
 * team-wide cooldown is not one player's cover. Describes, never blames (reading rule 6). Read when
 * a card is drawn: a change here needs only wow:game-cards, never a re-measure.
 */
class GameMomentsService
{
    /** Crowd control that stops a player acting (RoundAnalysisService::LOCKOUT). */
    private const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

    /** Overlap of control and a defensive, in seconds, before it counts as spent. */
    private const MIN_SPENT = 1.0;

    /** Control on a teammate this long inside their go before it is a moment. */
    private const MIN_CONTROLLED = 2.0;

    /** Seconds after a go starts that count as its opening casts... */
    private const OPENING = 4.0;

    /** ...and within which a big defensive counts as answering it. */
    private const ANSWER_WITHIN = 12.0;

    /** A defensive's cooldown, in seconds, before it is a big one. */
    private const BIG_COOLDOWN = 45;

    /** Defensive by the classification, but a way out of control rather than cover. */
    private const BREAKS = ["Gladiator's Medallion", 'Adaptation', 'Will of the Forsaken', 'Every Man for Himself', 'Escape Artist', 'Divine Shield', 'Ice Block', 'Cloak of Shadows', 'Blessing of Protection', 'Aspect of the Turtle', 'Dispersion'];

    /** Tagged defensive, but not cover against crowd control: a grip moves someone, it does not protect them. */
    private const NOT_COVER = ["Gladiator's Medallion", 'Adaptation', 'Will of the Forsaken', 'Every Man for Himself', 'Escape Artist', 'Leap of Faith'];

    /** At most this many moments of each kind in a round, the costliest first. */
    private const PER_KIND = 3;

    /** @var array<string, int>|null name => cooldown seconds, the big defensives */
    private ?array $big = null;

    /**
     * Every moment in one round, in time order.
     *
     * @param  array  $a  the round's stored analysis
     * @param  array<string, array<string, true>>  $dispellable  spec => debuff names its dispels remove
     * @return array<int, array>
     */
    public function forRound(array $a, array $dispellable = []): array
    {
        if (($a['version'] ?? 0) < 16 || empty($a['timeline'])) {
            return [];
        }

        $players = collect($a['players'])->keyBy('guid');
        $me = $players->firstWhere('logger', true);
        if ($me === null) {
            return [];
        }

        $through = $this->controlThroughDefensive($a, $players, $me);
        // Control in their go that is also control through a defensive is one moment: the second
        // only adds how long the player was free to act, which goes into the first.
        $inGo = [];
        foreach ($this->controlInTheirGoes($a, $players, $me, $dispellable[$me['spec']] ?? []) as $m) {
            $same = collect($through)->search(fn ($c) => $c['t'] === $m['t'] && $c['spells'][0] === $m['spells'][0]);
            if ($same === false) {
                $inGo[] = $m;
            } else {
                $through[$same]['text'] .= ' '.$m['free'];
            }
        }
        $moments = array_merge($through, $inGo, $me['healer'] ? $this->goOpenings($a, $players, $me) : []);
        usort($moments, fn ($x, $y) => $x['t'] <=> $y['t']);

        return $moments;
    }

    // ------------------------------------------------------------------ control through a defensive

    private function controlThroughDefensive(array $a, $players, array $me): array
    {
        $tl = $a['timeline'];
        $cover = $this->personalCover($tl['defUp']);
        $out = [];
        foreach ($tl['control'] as [$on, $by, $spell, $dr, $from, $to]) {
            if (! in_array($dr, self::LOCKOUT, true) || ! isset($players[$on]) || $to - $from < self::MIN_SPENT) {
                continue;
            }
            $spent = [];
            foreach ($cover as [$dOn, $dBy, $dSpell, $dFrom, $dTo]) {
                $overlap = min($to, $dTo) - max($from, $dFrom);
                if ($dOn === $on && $overlap >= self::MIN_SPENT) {
                    $spent[$dSpell] = max($spent[$dSpell] ?? 0, round($overlap, 1));
                }
            }
            if ($spent === []) {
                continue;
            }

            $victim = $players[$on];
            $ours = $victim['side'] === $me['side'];
            $healer = $players->first(fn ($p) => $p['side'] === $victim['side'] && $p['healer']);
            $defs = $this->list(array_keys($spent));
            $lost = max($spent);
            $caster = $players[$by] ?? null;
            $out[] = [
                'kind' => 'control',
                't' => $from,
                'clock' => $this->clock($from),
                'tone' => $ours ? 'warn' : 'good',
                'title' => $ours
                    ? "{$spell} on {$this->short($victim['name'])} through {$defs}"
                    : "Your team's {$spell} on {$this->short($victim['name'])} through {$defs}",
                'text' => sprintf('%s cast it while %s %s up, and %s %s of %s. %s',
                    $this->who($caster, $me, $ours ? 'Enemy' : 'Your'), $defs, count($spent) > 1 ? 'were' : 'was',
                    $ours ? 'lost' : 'took away', $this->secs($lost), count($spent) > 1 ? 'each' : 'it',
                    $this->howEnded($a, $on, $spell, $to, $me)),
                'spells' => array_merge([$spell], array_keys($spent)),
                'steps' => $this->steps($a, $players, $from, $to, [$on => $spell], $healer['guid'] ?? null, $me),
                'hp' => $this->hpSpan($tl, $on, $from, $to + 3),
                'hpOf' => $this->short($victim['name']),
                'during' => $healer && $ours ? ['who' => $healer['guid'] === $me['guid'] ? 'You' : $this->short($healer['name']), 'casts' => $this->castsBetween($tl, $healer['guid'], $from, $to)] : null,
                'cost' => $lost,
            ];
        }

        return $this->costliest($out);
    }

    /** "You dispelled it after...", "broken with...", or "it ran its course". */
    private function howEnded(array $a, string $on, string $spell, float $to, array $me): string
    {
        $names = collect($a['players'])->pluck('name', 'guid');
        foreach ($a['dispels'] ?? [] as $d) {
            if ($d['on'] === $on && $d['removed'] === $spell && abs($d['t'] - $to) <= 0.4) {
                return ($d['by'] === $me['guid'] ? 'You dispelled it' : 'Dispelled by '.$this->short($names[$d['by']] ?? '?')).' ('.$d['spell'].').';
            }
        }
        foreach ($a['timeline']['casts'][$on] ?? [] as [$t, $cast]) {
            if (abs($t - $to) <= 0.4 && in_array($cast, self::BREAKS, true)) {
                return "Broken with {$cast}.";
            }
        }

        return 'It ran its course or broke on damage.';
    }

    // ------------------------------------------------------------------ control in their go

    private function controlInTheirGoes(array $a, $players, array $me, array $dispellable): array
    {
        if ($dispellable === []) {
            return [];
        }
        $tl = $a['timeline'];
        $myLock = array_values(array_map(fn ($c) => [$c[4], $c[5]],
            array_filter($tl['control'], fn ($c) => $c[0] === $me['guid'] && in_array($c[3], self::LOCKOUT, true))));
        $theirGoes = array_values(array_filter($a['goes'], fn ($g) => $g['side'] !== $me['side']));
        $out = [];
        foreach ($tl['control'] as [$on, $by, $spell, $dr, $cFrom, $cTo]) {
            $mate = $players[$on] ?? null;
            if (! $mate || $mate['side'] !== $me['side'] || $on === $me['guid'] || ! isset($dispellable[$spell]) || ! in_array($dr, self::LOCKOUT, true)) {
                continue;
            }
            $go = collect($theirGoes)->first(fn ($g) => $cFrom < (float) $g['to'] + 1 && $cTo > (float) $g['from']);
            if ($go === null || $cTo - $cFrom < self::MIN_CONTROLLED) {
                continue;
            }
            $free = ($cTo - $cFrom) - $this->overlapWith($myLock, $cFrom, $cTo);
            if ($free < 1.0) {
                continue;   // locked out for nearly all of it: nothing to choose
            }
            $dispel = collect($a['dispels'] ?? [])->first(fn ($d) => $d['by'] === $me['guid'] && $d['on'] === $on && $d['removed'] === $spell && abs($d['t'] - $cTo) <= 0.4);
            $delay = $dispel ? round($dispel['t'] - $cFrom, 1) : null;
            $target = $this->short($players[$go['target']]['name'] ?? '?');
            $out[] = [
                'kind' => 'dispel',
                't' => $cFrom,
                'clock' => $this->clock($cFrom),
                'tone' => $delay !== null && $delay <= 1.5 ? 'good' : ($delay === null ? 'warn' : 'neutral'),
                'title' => "{$spell} on {$this->short($mate['name'])} in an enemy go",
                'text' => sprintf('%s was %s for %s inside the enemy go on %s, and you were free to act for %s of it. %s',
                    $this->short($mate['name']), $spell === 'Mind Control' ? 'mind controlled' : 'controlled', $this->secs($cTo - $cFrom), $target, $this->secs($free),
                    $delay === null ? 'It was not dispelled by you: '.lcfirst($this->howEnded($a, $on, $spell, $cTo, $me)) : ($delay <= 0.2 ? 'You dispelled it at once.' : "You dispelled it after {$this->secs($delay)}.")),
                'spells' => [$spell],
                'steps' => $this->steps($a, $players, $cFrom, $cTo, [$on => $spell], $me['guid'], $me),
                'hp' => $this->hpSpan($tl, $go['target'], $cFrom, $cTo + 3),
                'hpOf' => $target,
                'during' => ['who' => 'You', 'casts' => $this->castsBetween($tl, $me['guid'], $cFrom, $dispel['t'] ?? $cTo)],
                'free' => "You were free to act for {$this->secs($free)} of it.",
                'caveat' => 'Whether a dispel was the better global depends on what else needed it then. The log shows what happened, not what should have.',
                'cost' => $delay ?? ($cTo - $cFrom),
            ];
        }

        return $this->costliest($out);
    }

    // ------------------------------------------------------------------ the start of their go

    private function goOpenings(array $a, $players, array $me): array
    {
        $tl = $a['timeline'];
        $big = $this->bigDefensives();
        $mine = collect($a['defensives'][$me['side']]['rows'] ?? [])->where('who', $me['guid'])
            ->filter(fn ($r) => isset($big[$r['spell']]))->sortBy('t');
        $out = [];
        foreach ($a['goes'] as $go) {
            if ($go['side'] === $me['side'] || ! isset($players[$go['target']])) {
                continue;
            }
            [$gFrom, $gTo] = [(float) $go['from'], (float) $go['to']];
            $first = array_slice($this->castsBetween($tl, $me['guid'], $gFrom, $gFrom + self::OPENING, raw: true), 0, 3);
            $answer = $mine->first(fn ($r) => $r['t'] >= $gFrom - 1 && $r['t'] <= min($gTo, $gFrom + self::ANSWER_WITHIN));
            $hp = $this->hpSpan($tl, $go['target'], $gFrom, $gTo);
            $killed = ! empty($go['kill']);
            $target = $this->short($players[$go['target']]['name']);
            $lead = $first ? round($first[0][0] - $gFrom, 1) : null;
            $out[] = [
                'kind' => 'opening',
                't' => $gFrom,
                'clock' => $this->clock($gFrom),
                'tone' => $killed ? 'warn' : 'neutral',
                'title' => "Their go on {$target}".($killed ? ': it killed' : ''),
                'text' => sprintf('%s went from %d%% to %d%%. %s %s',
                    $target, $hp['start'] ?? 100, $hp['low'] ?? 100,
                    $first ? 'Your first cast came '.($lead <= 0.1 ? 'as it started' : "after {$this->secs($lead)}").': '.$this->list(array_map(fn ($c) => $c[1], $first)).'.' : 'You cast nothing in its first '.(int) self::OPENING.' seconds.',
                    $answer ? "Your {$answer['spell']} came ".($answer['t'] - $gFrom <= 0.1 ? 'as it started.' : 'after '.$this->secs($answer['t'] - $gFrom).'.') : 'No big defensive of yours went into it in its first '.(int) self::ANSWER_WITHIN.' seconds.'),
                'spells' => array_values(array_unique(array_merge(array_map(fn ($c) => $c[1], $first), $answer ? [$answer['spell']] : []))),
                'steps' => $this->steps($a, $players, $gFrom, min($gTo, $gFrom + 8), [], $me['guid'], $me),
                'hp' => $hp,
                'hpOf' => $target,
                'cost' => (100 - ($hp['low'] ?? 100)) + ($killed ? 100 : 0),
            ];
        }

        return $this->costliest($out);
    }

    // ------------------------------------------------------------------ helpers

    /** name => cooldown, every defensive in the classification with a cooldown of BIG_COOLDOWN or more. */
    private function bigDefensives(): array
    {
        if ($this->big !== null) {
            return $this->big;
        }
        $path = base_path('data/arena-logs/spell-classification/defensive-cooldowns.json');
        $this->big = [];
        foreach (json_decode((string) @file_get_contents($path), true) ?: [] as $e) {
            if (($e['cooldownSeconds'] ?? 0) >= self::BIG_COOLDOWN && ! in_array($e['name'], self::NOT_COVER, true)) {
                $this->big[$e['name']] = (int) $e['cooldownSeconds'];
            }
        }

        return $this->big;
    }

    /** The big defensive spans that were one player's own cover: not cast on several players at once. */
    private function personalCover(array $defUp): array
    {
        $big = $this->bigDefensives();
        $out = [];
        foreach ($defUp as $d) {
            [$on, $by, $spell, $from] = $d;
            if (! isset($big[$spell])) {
                continue;
            }
            $teamWide = collect($defUp)->contains(fn ($o) => $o[2] === $spell && $o[1] === $by && $o[0] !== $on && abs($o[3] - $from) <= 1.0);
            if (! $teamWide) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** A short timeline of the moment: one player's casts, and the control and dispels it is about. */
    private function steps(array $a, $players, float $from, float $to, array $marks, ?string $whose, array $me): array
    {
        $tl = $a['timeline'];
        $name = fn (?string $g) => $g === $me['guid'] ? 'You' : $this->short($players[$g]['name'] ?? '?');
        $rows = [];
        if ($whose) {
            foreach ($this->castsBetween($tl, $whose, $from - 1.5, $to + 1.5, raw: true) as [$t, $spell, $on]) {
                $rows[] = ['t' => $t, 'who' => $name($whose), 'spell' => $spell, 'on' => $on ? $name($on) : null];
            }
        }
        foreach ($tl['control'] as [$on, $by, $spell, $dr, $cFrom, $cTo]) {
            if (isset($marks[$on]) && $spell === $marks[$on] && $cFrom >= $from - 0.5 && $cFrom <= $to) {
                $rows[] = ['t' => $cFrom, 'who' => $name($by), 'spell' => $spell, 'on' => $name($on), 'mark' => 'control'];
            }
        }
        foreach ($a['dispels'] ?? [] as $d) {
            if (isset($marks[$d['on']]) && $d['removed'] === $marks[$d['on']] && $d['t'] >= $from - 0.5 && $d['t'] <= $to + 1.5) {
                $rows[] = ['t' => $d['t'], 'who' => $name($d['by']), 'spell' => $d['spell'], 'on' => $name($d['on']), 'mark' => 'dispel', 'removed' => $d['removed']];
            }
        }
        // One press seen twice (the cast and its dispel, or a cast logged on two targets) shows once,
        // and the marked row wins: a dispel reads as a dispel.
        usort($rows, fn ($x, $y) => [round($x['t'], 1), isset($x['mark']) ? 0 : 1] <=> [round($y['t'], 1), isset($y['mark']) ? 0 : 1]);
        $rows = array_values(collect($rows)->unique(fn ($r) => round($r['t'], 1).'|'.$r['who'].'|'.$r['spell'])->all());

        return array_map(fn ($r) => $r + ['clock' => $this->clock($r['t'])], array_slice($rows, 0, 10));
    }

    /** A player's casts in a window: names (default) or raw [t, spell, on]. */
    private function castsBetween(array $tl, string $guid, float $from, float $to, bool $raw = false): array
    {
        $out = [];
        $last = null;
        foreach ($tl['casts'][$guid] ?? [] as $c) {
            if ($c[0] >= $from && $c[0] <= $to && ($last === null || $last[1] !== $c[1] || $c[0] - $last[0] > 0.2)) {
                $out[] = $raw ? $c : $c[1];
                $last = $c;
            }
        }

        return $out;
    }

    /** Health at the start of a window and its lowest through it. */
    private function hpSpan(array $tl, string $guid, float $from, float $to): array
    {
        $series = $tl['hp'][$guid] ?? [];
        if ($series === []) {
            return ['start' => null, 'low' => null];
        }
        $s = max(0, min(count($series) - 1, (int) floor($from)));
        $e = max($s, min(count($series) - 1, (int) ceil($to)));

        return ['start' => $series[$s], 'low' => min(array_slice($series, $s, $e - $s + 1))];
    }

    private function overlapWith(array $intervals, float $from, float $to): float
    {
        $sum = 0.0;
        foreach ($intervals as [$f, $t]) {
            $sum += max(0, min($to, $t) - max($from, $f));
        }

        return min($sum, $to - $from);
    }

    private function costliest(array $moments): array
    {
        usort($moments, fn ($x, $y) => $y['cost'] <=> $x['cost']);

        return array_slice($moments, 0, self::PER_KIND);
    }

    /** "You", or "Enemy Discipline Priest Bigchalupaz". */
    private function who(?array $p, array $me, string $team): string
    {
        if ($p === null) {
            return $team === 'Enemy' ? 'They' : 'Your team';
        }

        return $p['guid'] === $me['guid'] ? 'You' : $team.' '.$p['spec'].' '.$this->short($p['name']);
    }

    private function list(array $names): string
    {
        $names = array_values(array_unique($names));

        return count($names) <= 1 ? ($names[0] ?? '') : implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    private function secs(float $s): string
    {
        return number_format(max(0, $s), 1).'s';
    }

    private function clock(float $s): string
    {
        return sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
    }

    private function short(string $name): string
    {
        return explode('-', $name)[0];
    }
}
