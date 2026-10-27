<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use App\Support\DefensiveFit;

/**
 * Moments (2026-10-17, reworked 2026-10-28): situations in a round that a player would recognise,
 * each with what was up, what was pressed and what it cost, read from the round's stored timeline
 * (RoundAnalysisService version 16). The measures say how much; a moment says when, and what
 * happened around it.
 *
 * Chriso's review of the cards on 28 Oct set what a moment is for: something to repeat, or a chance
 * that was there. The kinds:
 *  - best go: the biggest fall your team caused on them (FallsService). What did it (your
 *    cooldowns, the longest chain on their healer in it), what saved them, and what your team still
 *    had ready and did not press;
 *  - kill window: an enemy with every big defensive that could save them on cooldown (theirs and
 *    their healer's, by DefensiveFit's reach), with their trinkets, and what your team did in it;
 *  - crowd control that worked: a peel (control on an enemy while their damage cooldown ran: "you
 *    Cycloned the Warrior in his Avatar"), an interrupt that stopped their crowd control, and their
 *    crowd control that never landed because of what was pressed just before it (Fade, Death);
 *  - control that stops healing through a big defensive: only Cyclone and Mind Control. Chriso:
 *    a player waits until low to press a big defensive, which buys time until their healer is free;
 *    a Cyclone or Mind Control on them then means no heal reaches them, the defensive runs out while
 *    they sit low, and they come out needing a heal with nothing left. A stun through a defensive is
 *    not that: their healer keeps healing them through it. The 17 Oct rule (any lockout through any
 *    big defensive) was wrong;
 *  - control in their go that you could dispel, and (for a healer) the start of their goes.
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

    /** Crowd control that no heal reaches through: the target is immune to healing, or on the other team. */
    private const UNHEALABLE = ['Cyclone', 'Mind Control'];

    /**
     * Pressed just before crowd control that then never landed. Fade and Shadow Word: Death from
     * Chriso (28 Oct); the rest are immunities and redirects.
     */
    private const AVOIDS = ['Fade', 'Shadow Word: Death', 'Grounding Totem', 'Spell Reflection', 'Nether Ward',
        'Ice Block', 'Divine Shield', 'Cloak of Shadows', 'Aspect of the Turtle', 'Blessing of Spellwarding'];

    /** Overlap of control and a defensive, in seconds, before it counts as spent. */
    private const MIN_SPENT = 1.0;

    /** Control on a teammate this long inside their go before it is a moment. */
    private const MIN_CONTROLLED = 2.0;

    /** Seconds of an enemy's damage cooldown spent in your control before it is a peel. */
    private const MIN_PEEL = 1.5;

    /** Seconds with every big defensive that could save someone down before it is a window. */
    private const MIN_WINDOW = 6;

    /** Seconds without a control of a kind before its diminishing returns reset (FallBreakdownService). */
    private const DR_RESET = 20.0;

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

    /** What a defensive covers (DefensiveFit) for it to count as one that could save someone. */
    private const SAVES = ['all', 'physical', 'magic', 'heal'];

    private const MEDALLION = "Gladiator's Medallion";

    /** At most this many moments of each kind in a round, the costliest first. */
    private const PER_KIND = 3;

    /** @var array<string, int>|null name => cooldown seconds, the big defensives */
    private ?array $big = null;

    /** @var array<string, int>|null name => cooldown seconds, the big damage cooldowns */
    private ?array $bigOff = null;

    /** @var array<string, string>|null crowd control name => its kind */
    private ?array $cc = null;

    /** @var array<string, ?float> offensive cooldown name => its duration in the spell data */
    private array $durations = [];

    public function __construct(private FallBreakdownService $breakdown) {}

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
        $a['timeline'] += ['casts' => [], 'hp' => [], 'control' => [], 'defUp' => []];
        $falls = app(FallsService::class)->read($a)['falls'];
        $frames = app(WatchPageService::class)->frames($a);

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
        $moments = array_merge(
            $this->bestGo($a, $players, $me, $falls, $frames),
            $this->killWindows($a, $players, $me, $frames),
            $this->peels($a, $players, $me, $falls),
            $this->ccKicks($a, $players, $me, $falls),
            $this->avoided($a, $players, $me),
            $through, $inGo,
            $me['healer'] ? $this->goOpenings($a, $players, $me) : [],
        );
        usort($moments, fn ($x, $y) => $x['t'] <=> $y['t']);

        return $moments;
    }

    // ------------------------------------------------------------------ your best go

    /** The biggest fall your team caused: a kill first, then the deepest. */
    private function bestGo(array $a, $players, array $me, array $falls, array $frames): array
    {
        $ours = array_values(array_filter($falls, fn ($f) => $f['side'] === $me['side'] && isset($players[$f['on']])));
        if ($ours === []) {
            return [];
        }
        usort($ours, fn ($x, $y) => [(int) $y['died'], $y['high'] - $y['low']] <=> [(int) $x['died'], $x['high'] - $x['low']]);
        $f = $ours[0];
        $target = $players[$f['on']];
        $ourSide = fn (?string $g) => $g !== null && ($players[$g]['side'] ?? null) === $me['side'];
        $name = fn (?string $g) => $g === $me['guid'] ? 'You' : $this->short($players[$g]['name'] ?? '?');

        $offensive = array_values(array_filter($f['links'], fn ($l) => $l['cat'] === 'offensive' && $ourSide($l['by'])));
        $control = array_values(array_filter($f['links'], fn ($l) => $l['cat'] === 'control' && $ourSide($l['by'])));
        $healer = $players->first(fn ($p) => $p['side'] !== $me['side'] && ! empty($p['healer']));
        $chain = $healer ? $this->longestChain($a['timeline']['control'], $healer['guid'], (float) $f['from'] - FallsService::LEAD, (float) $f['to']) : null;

        // What your team had ready as it started and did not press in it.
        $pressed = [];
        foreach ($f['links'] as $l) {
            $pressed[$l['by'].'|'.$l['spell']] = true;
        }
        $unused = [];
        foreach ($frames as $g => $fr) {
            if (($fr['side'] ?? null) !== $me['side'] || ($fr['died'] !== null && $fr['died'] <= $f['from'])) {
                continue;
            }
            foreach ($fr['abilities'] as $ab) {
                if (in_array($ab['kind'], ['offensive', 'control'], true) && ! isset($pressed[$g.'|'.$ab['name']])
                    && ! $this->pressedBetween($ab, $f['from'] - FallsService::LEAD, $f['to']) && $this->breakdown->readyAt($ab, (float) $f['from'])) {
                    $unused[] = ($g === $me['guid'] ? 'your ' : $this->short($fr['name'] ?? '?')."'s ").$ab['name'];
                }
            }
        }

        $who = $this->short($target['name']);
        $text = sprintf('%s went from %d%% to %d%%%s.', $who, $f['high'], $f['low'], $f['died'] ? ' and died' : '');
        $whose = fn (?string $g) => $g === $me['guid'] ? 'your ' : $this->short($players[$g]['name'] ?? '?')."'s ";
        $text .= $offensive ? ' Cooldowns in it: '.$this->list(array_map(fn ($l) => $whose($l['by']).$l['spell'], $offensive)).'.' : ' No offensive cooldown went into it.';
        if ($healer) {
            $text .= $chain
                ? sprintf(' Their healer %s was crowd controlled for %s in a row (%s).', $this->short($healer['name']), $this->secs($chain['length']), $this->list($chain['spells']))
                : sprintf(' Their healer %s was not crowd controlled in it.', $this->short($healer['name']));
        }
        $text .= $f['answers'] ? ' What they answered with: '.$this->list(array_map(fn ($x) => $whose($x['who']).$x['spell'], $f['answers'])).'.' : ' They pressed no defensive in it.';

        $steps = [];
        foreach ($f['links'] as $l) {
            if ($ourSide($l['by'])) {
                $steps[] = ['t' => (float) $l['t'], 'who' => $name($l['by']), 'spell' => $l['spell'], 'on' => $l['on'] ? $name($l['on']) : null, 'mark' => $l['cat'] === 'control' ? 'control' : null];
            }
        }
        foreach ($f['answers'] as $x) {
            $steps[] = ['t' => (float) $x['t'], 'who' => $name($x['who']), 'spell' => $x['spell'], 'on' => null, 'mark' => 'answer'];
        }
        usort($steps, fn ($x, $y) => $x['t'] <=> $y['t']);

        return [[
            'kind' => 'best',
            't' => (float) $f['from'],
            'clock' => $this->clock((float) $f['from']),
            'tone' => 'good',
            'title' => "Your best go: {$who} ({$target['spec']})".($f['died'] ? ', killed' : ' to '.$f['low'].'%'),
            'text' => $text,
            'spells' => array_values(array_unique(array_merge(array_column($offensive, 'spell'), array_column($control, 'spell')))),
            'steps' => array_map(fn ($s) => $s + ['clock' => $this->clock($s['t'])], array_slice($steps, 0, 12)),
            'hp' => ['start' => $f['high'], 'low' => $f['low']],
            'hpOf' => $who,
            'extra' => $unused ? 'Ready on your side when it started and not pressed in it: '.$this->list(array_slice($unused, 0, 6)).'.' : null,
            'cost' => 0,
        ]];
    }

    /** The longest run of lockout on one player overlapping a window, as one chain (gaps under 0.5s). */
    private function longestChain(array $control, string $on, float $from, float $to): ?array
    {
        $spans = array_values(array_filter($control, fn ($c) => $c[0] === $on && in_array($c[3], self::LOCKOUT, true) && $c[5] > $from && $c[4] < $to));
        usort($spans, fn ($x, $y) => $x[4] <=> $y[4]);
        $best = null;
        $cur = null;
        foreach ($spans as $c) {
            if ($cur !== null && $c[4] <= $cur['to'] + 0.5) {
                $cur['to'] = max($cur['to'], $c[5]);
                $cur['spells'][] = $c[2];
            } else {
                $cur = ['from' => $c[4], 'to' => $c[5], 'spells' => [$c[2]]];
            }
            $cur['length'] = $cur['to'] - $cur['from'];
            if ($best === null || $cur['length'] > $best['length']) {
                $best = $cur;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------ a window to kill

    /**
     * An enemy with every big defensive that could save them on cooldown (theirs, and their team's
     * that reach them), for MIN_WINDOW seconds or more. "Back" is the latest it can be: cooldown
     * reduction is not in the data, so a window can be shorter than shown.
     */
    private function killWindows(array $a, $players, array $me, array $frames): array
    {
        $fit = DefensiveFit::tags();
        $big = $this->bigDefensives();
        $hp = $a['timeline']['hp'];
        $end = 0;
        foreach ($hp as $series) {
            $end = max($end, count($series) - 1);
        }
        $out = [];
        foreach ($players as $x) {
            if ($x['side'] === $me['side'] || ! isset($frames[$x['guid']])) {
                continue;
            }
            $covers = [];
            foreach ($frames as $g => $fr) {
                if (($fr['side'] ?? null) !== $x['side']) {
                    continue;
                }
                foreach ($fr['abilities'] as $ab) {
                    $tag = $fit[$ab['name']] ?? null;
                    $reach = $g === $x['guid'] ? ['self', 'self-or-other', 'team'] : ['other', 'self-or-other', 'team'];
                    if ($ab['kind'] === 'answers' && isset($big[$ab['name']]) && $tag && in_array($tag['covers'], self::SAVES, true) && in_array($tag['reach'], $reach, true)) {
                        $covers[] = [$g, $ab];
                    }
                }
            }
            if ($covers === []) {
                continue;
            }
            $died = $frames[$x['guid']]['died'];
            $run = null;
            for ($s = 0; $s <= $end + 1; $s++) {
                $down = $s <= $end && ($died === null || $s < $died) && collect($covers)->every(fn ($c) => ! $this->breakdown->readyAt($c[1], $s)
                    || ($frames[$c[0]]['died'] !== null && $s >= $frames[$c[0]]['died']));
                if ($down) {
                    $run ??= $s;

                    continue;
                }
                if ($run !== null && $s - $run >= self::MIN_WINDOW) {
                    $out[] = $this->window($a, $players, $me, $frames, $x, $covers, (float) $run, (float) $s);
                }
                $run = null;
            }
        }

        return $this->costliest($out);
    }

    private function window(array $a, $players, array $me, array $frames, array $x, array $covers, float $from, float $to): array
    {
        $who = $this->short($x['name']);
        $owned = array_map(fn ($c) => ($c[0] === $x['guid'] ? '' : $this->short($frames[$c[0]]['name'] ?? '?')."'s ").$c[1]['name'], $covers);
        $healer = $players->first(fn ($p) => $p['side'] === $x['side'] && ! empty($p['healer']));
        $trinketDown = function (?string $g) use ($frames, $from) {
            $ab = $g ? collect($frames[$g]['abilities'] ?? [])->firstWhere('name', self::MEDALLION) : null;

            return $ab !== null && ! $this->breakdown->readyAt($ab, $from);
        };
        $healerTrinket = $healer && $healer['guid'] !== $x['guid'] && $trinketDown($healer['guid']);
        $ownTrinket = $trinketDown($x['guid']);
        $span = $this->hpSpan($a['timeline'], $x['guid'], $from, $to);
        $killed = $frames[$x['guid']]['died'] !== null && $frames[$x['guid']]['died'] <= $to + 1;

        // What your team pressed in it, and what it had ready as it opened.
        $pressed = [];
        $ready = [];
        foreach ($frames as $g => $fr) {
            if (($fr['side'] ?? null) !== $me['side'] || ($fr['died'] !== null && $fr['died'] <= $from)) {
                continue;
            }
            $label = $g === $me['guid'] ? 'your ' : $this->short($fr['name'] ?? '?')."'s ";
            foreach ($fr['abilities'] as $ab) {
                if ($ab['kind'] !== 'offensive') {
                    continue;
                }
                if ($this->pressedBetween($ab, $from, $to)) {
                    $pressed[] = $label.$ab['name'];
                } elseif ($this->breakdown->readyAt($ab, $from)) {
                    $ready[] = $label.$ab['name'];
                }
            }
        }

        $text = sprintf('From %s to %s, %s had none of %s ready%s%s.', $this->clock($from), $this->clock($to), $who, $this->list($owned),
            $ownTrinket ? ', and their trinket was down' : '',
            $healerTrinket ? ($ownTrinket ? ', as was their healer\'s' : ', and their healer\'s trinket was down') : '');
        $text .= sprintf(' %s\'s lowest health in it was %d%%%s.', $who, $span['low'] ?? 100, $killed ? ', and they died' : '');
        $text .= $pressed ? ' Your team pressed '.$this->list($pressed).' in it.' : ' Your team pressed no offensive cooldown in it'.($ready ? '; ready as it opened: '.$this->list($ready).'.' : ', and none was ready as it opened.');

        return [
            'kind' => 'window',
            't' => $from,
            'clock' => $this->clock($from),
            'tone' => $killed ? 'good' : ($pressed ? 'neutral' : 'warn'),
            'title' => "{$who} ({$x['spec']}) with no big defensive for ".$this->secs($to - $from, 0).($killed ? ': killed' : ''),
            'text' => $text,
            'spells' => array_values(array_unique(array_map(fn ($c) => $c[1]['name'], $covers))),
            'steps' => [],
            'hp' => $span,
            'hpOf' => $who,
            'caveat' => 'A defensive can be back sooner than shown: cooldown reduction is not in the data.',
            'cost' => ($to - $from) + ($healerTrinket ? 10 : 0) + ($ownTrinket ? 5 : 0) + ($pressed ? 0 : 5),
        ];
    }

    /** Whether a frame's button was pressed between two moments. */
    private function pressedBetween(array $ab, float $from, float $to): bool
    {
        foreach ($ab['presses'] ?? [] as $p) {
            if ($p >= $from && $p <= $to) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ crowd control that worked

    /** Your team's control on an enemy while their damage cooldown ran: the seconds of it taken away. */
    private function peels(array $a, $players, array $me, array $falls): array
    {
        // Damage cooldowns of BIG_COOLDOWN or more: a peel on Flurry (30s) took nothing worth saying.
        $big = $this->bigOffensives();
        $spans = array_values(array_filter($this->offensiveSpans($a, $players), fn ($s) => isset($big[$s[1]])));
        $out = [];
        foreach ($a['timeline']['control'] as [$on, $by, $spell, $dr, $from, $to]) {
            $caster = $players[$by] ?? null;
            $victim = $players[$on] ?? null;
            if (! $caster || ! $victim || $caster['side'] !== $me['side'] || $victim['side'] === $me['side'] || ! empty($victim['healer']) || ! in_array($dr, self::LOCKOUT, true)) {
                continue;
            }
            $best = null;
            foreach ($spans as $s) {
                $overlap = min($to, $s[3]) - max($from, $s[2]);
                if ($s[0] === $on && $overlap >= self::MIN_PEEL && ($best === null || $overlap > $best[1])) {
                    $best = [$s, $overlap];
                }
            }
            if ($best === null) {
                continue;
            }
            [[, $cd, $cdFrom, $cdTo, $exact], $overlap] = $best;
            $go = collect($falls)->first(fn ($f) => $f['side'] === $victim['side'] && $f['from'] <= $to && $f['to'] >= $from);
            $vName = $this->short($victim['name']);
            $out[] = [
                'kind' => 'peel',
                't' => $from,
                'clock' => $this->clock($from),
                'tone' => 'good',
                'title' => "{$spell} on {$vName} during {$cd}",
                'text' => sprintf('%s cast %s on their %s %s %s after %s began: %s of its %s%s went by in it.%s',
                    $this->who($caster, $me, 'Your'), $spell, $victim['spec'], $vName, $this->secs(max(0, $from - $cdFrom)), $cd,
                    $this->secs($overlap), $exact ? '' : 'up to ', $this->secs($cdTo - $cdFrom),
                    $go ? sprintf(' It was inside their go on %s, who got to %d%%.', $this->short($players[$go['on']]['name'] ?? '?'), $go['low']) : ''),
                'spells' => [$spell, $cd],
                'steps' => [],
                'hp' => ['start' => null, 'low' => null],
                'cost' => $overlap,
            ];
        }

        return $this->costliest($out);
    }

    /**
     * Each enemy damage cooldown as [guid, name, from, to, exact]: the buff's own span when the round
     * kept them (timeline.offUp, from 28 Oct), else the press and the spell data's duration.
     */
    private function offensiveSpans(array $a, $players): array
    {
        if (isset($a['timeline']['offUp'])) {
            return array_map(fn ($s) => [$s[0], $s[1], (float) $s[2], (float) $s[3], true], $a['timeline']['offUp']);
        }
        $classes = app(ArenaLogService::class)->offensiveDefensiveClassification()['byName'] ?? [];
        $out = [];
        $want = [];
        foreach ($a['timeline']['casts'] as $g => $list) {
            foreach ($list as $c) {
                if (isset($players[$g]) && (($classes[$c[1]] ?? [])['offensive'] ?? false)) {
                    $want[$c[1]] = true;
                    $out[] = [$g, $c[1], (float) $c[0]];
                }
            }
        }
        $durations = $this->durations(array_keys($want));

        return array_values(array_filter(array_map(fn ($o) => isset($durations[$o[1]]) ? [$o[0], $o[1], $o[2], $o[2] + $durations[$o[1]], false] : null, $out)));
    }

    /** @return array<string, float> name => the spell data's duration, where it has one under a minute */
    private function durations(array $names): array
    {
        $missing = array_values(array_diff($names, array_keys($this->durations)));
        if ($missing !== []) {
            $rows = Spell::query()->where('patch_id', Patch::where('is_current', true)->value('id'))->whereIn('name', $missing)
                ->where('duration_seconds', '>', 0)->where('duration_seconds', '<=', 60)->orderBy('is_passive')
                ->get(['name', 'duration_seconds']);
            foreach ($missing as $n) {
                $this->durations[$n] = ($d = $rows->firstWhere('name', $n)) ? (float) $d->duration_seconds : null;
            }
        }

        return array_filter(array_intersect_key($this->durations, array_flip($names)));
    }

    /** An interrupt by your team that stopped one of their crowd control casts (timeline.kicks, from 28 Oct). */
    private function ccKicks(array $a, $players, array $me, array $falls): array
    {
        $healer = $players->first(fn ($p) => $p['side'] === $me['side'] && ! empty($p['healer']));
        $out = [];
        foreach ($a['timeline']['kicks'] ?? [] as [$t, $by, $on, $kick, $stopped, $dr]) {
            $kicker = $players[$by] ?? null;
            $caster = $players[$on] ?? null;
            if (! $kicker || ! $caster || $kicker['side'] !== $me['side'] || $caster['side'] === $me['side'] || $dr === null) {
                continue;
            }
            $lock = $healer ? collect($a['timeline']['control'])->first(fn ($c) => $c[0] === $healer['guid'] && in_array($c[3], self::LOCKOUT, true) && $c[4] <= $t && $c[5] > $t) : null;
            $go = collect($falls)->first(fn ($f) => $f['side'] !== $me['side'] && $f['from'] <= $t && $f['to'] >= $t);
            $context = array_filter([
                $lock ? sprintf('while %s in %s', $healer['guid'] === $me['guid'] ? 'you were' : 'your healer '.$this->short($healer['name']).' was', $lock[2]) : null,
                $go ? 'inside their go on '.$this->short($players[$go['on']]['name'] ?? '?') : null,
            ]);
            $out[] = [
                'kind' => 'kick',
                't' => (float) $t,
                'clock' => $this->clock((float) $t),
                'tone' => 'good',
                'title' => "{$stopped} interrupted",
                'text' => sprintf('%s interrupted their %s %s\'s %s with %s%s.', $this->who($kicker, $me, 'Your'), $caster['spec'], $this->short($caster['name']), $stopped, $kick,
                    $context ? ', '.implode(', ', $context) : ''),
                'spells' => [$stopped, $kick],
                'steps' => [],
                'hp' => ['start' => null, 'low' => null],
                'cost' => 1 + ($lock ? 2 : 0) + ($go ? 1 : 0),
            ];
        }

        return $this->costliest($out);
    }

    /** Their crowd control cast at your team that never landed, with what was pressed just before it. */
    private function avoided(array $a, $players, array $me): array
    {
        $tl = $a['timeline'];
        // Each control's kind as it landed in this round, else from the spell data.
        $seen = [];
        foreach ($tl['control'] as $x) {
            $seen[$x[2]] = $x[3];
        }
        $out = [];
        foreach ($tl['casts'] as $g => $list) {
            $caster = $players[$g] ?? null;
            if (! $caster || $caster['side'] === $me['side']) {
                continue;
            }
            foreach ($list as $c) {
                [$t, $spell] = $c;
                $target = $c[2] ?? null;
                if (! $target || ($players[$target]['side'] ?? null) !== $me['side']) {
                    continue;
                }
                $dr = $seen[$spell] ?? ($this->crowdControlNames()[$spell] ?? null);
                if (! in_array($dr, self::LOCKOUT, true)) {
                    continue;
                }
                $landed = collect($tl['control'])->contains(fn ($x) => $x[0] === $target && $x[2] === $spell && $x[4] >= $t - 0.5 && $x[4] <= $t + 2.5);
                if ($landed || $this->immuneByReturns($tl['control'], $target, $dr, (float) $t)) {
                    continue;
                }
                $press = null;
                foreach ($tl['casts'] as $g2 => $list2) {
                    if (($players[$g2]['side'] ?? null) !== $me['side']) {
                        continue;
                    }
                    foreach ($list2 as $p) {
                        if (in_array($p[1], self::AVOIDS, true) && $p[0] >= $t - 1.5 && $p[0] <= $t + 0.3
                            && ($g2 === $target || ($p[2] ?? null) === $target || ($p[2] ?? null) === null)) {
                            $press = [$g2, $p[1], (float) $p[0]];
                        }
                    }
                }
                if ($press === null) {
                    continue;
                }
                $victim = $target === $me['guid'] ? 'you' : $this->short($players[$target]['name']);
                $presser = $press[0] === $me['guid'] ? 'You' : $this->short($players[$press[0]]['name']);
                $lead = $t - $press[2];
                $out[] = [
                    'kind' => 'avoid',
                    't' => (float) $t,
                    'clock' => $this->clock((float) $t),
                    'tone' => 'good',
                    'title' => "{$spell} on {$victim} avoided with {$press[1]}",
                    'text' => sprintf('Their %s %s cast %s on %s and it never landed. %s pressed %s %s.', $caster['spec'], $this->short($caster['name']), $spell, $victim, $presser, $press[1],
                        $lead >= 0.05 ? $this->secs($lead).' before it' : 'as it came in'),
                    'spells' => [$spell, $press[1]],
                    'steps' => [],
                    'hp' => ['start' => null, 'low' => null],
                    'cost' => 1,
                ];
            }
        }

        return $this->costliest($out);
    }

    /** Whether $on was immune to $dr control at $t: two of that kind already in a chain under DR_RESET apart. */
    private function immuneByReturns(array $control, string $on, string $dr, float $t): bool
    {
        $spans = array_values(array_filter($control, fn ($c) => $c[0] === $on && $c[3] === $dr && $c[4] < $t - 0.1));
        usort($spans, fn ($x, $y) => $x[4] <=> $y[4]);
        $count = 0;
        $lastTo = null;
        foreach ($spans as $c) {
            $count = $lastTo !== null && $c[4] - $lastTo <= self::DR_RESET ? $count + 1 : 1;
            $lastTo = $c[5];
        }

        return $lastTo !== null && $t - $lastTo <= self::DR_RESET && $count >= 2;
    }

    /** @return array<string, string> crowd control name => its kind, from the spell data (spells.dr_category) */
    private function crowdControlNames(): array
    {
        if ($this->cc === null) {
            $byId = app(ArenaMomentService::class)->crowdControl();
            $this->cc = Spell::query()->where('patch_id', Patch::where('is_current', true)->value('id'))
                ->whereIn('spell_id', array_keys($byId))->get(['spell_id', 'name'])
                ->mapWithKeys(fn ($s) => [$s->display_name => $byId[$s->spell_id]])->all();
        }

        return $this->cc;
    }

    // ------------------------------------------------------------------ control that stops healing through a defensive

    private function controlThroughDefensive(array $a, $players, array $me): array
    {
        $tl = $a['timeline'];
        $cover = $this->personalCover($tl['defUp']);
        $out = [];
        foreach ($tl['control'] as [$on, $by, $spell, $dr, $from, $to]) {
            if (! in_array($spell, self::UNHEALABLE, true) || ! isset($players[$on]) || $to - $from < self::MIN_SPENT) {
                continue;
            }
            $spent = [];
            $left = [];
            foreach ($cover as [$dOn, $dBy, $dSpell, $dFrom, $dTo]) {
                $overlap = min($to, $dTo) - max($from, $dFrom);
                if ($dOn === $on && $overlap >= self::MIN_SPENT) {
                    $spent[$dSpell] = max($spent[$dSpell] ?? 0, round($overlap, 1));
                    $left[$dSpell] = max(0, $dTo - $to);
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
            $hp = $this->hpSpan($tl, $on, $from, $to + 3);
            $remaining = $this->list(array_map(fn ($d, $s) => $s < 0.5 ? "{$d} had run out" : "{$d} had {$this->secs($s)} left", array_keys($left), $left));
            $out[] = [
                'kind' => 'control',
                't' => $from,
                'clock' => $this->clock($from),
                'tone' => $ours ? 'warn' : 'good',
                'title' => $ours
                    ? "{$spell} on {$this->short($victim['name'])} through {$defs}"
                    : "Your team's {$spell} on {$this->short($victim['name'])} through {$defs}",
                'text' => sprintf('%s cast it at %s while %s %s up and %s was at %d%%. No heal could reach them for %s, %s. %s',
                    $this->who($caster, $me, $ours ? 'Enemy' : 'Your'), $this->clock($from), $defs, count($spent) > 1 ? 'were' : 'was',
                    $this->short($victim['name']), $hp['start'] ?? 100, $this->secs($to - $from),
                    'and '.$remaining.' when it ended',
                    $this->howEnded($a, $on, $spell, $to, $me)),
                'spells' => array_merge([$spell], array_keys($spent)),
                'steps' => $this->steps($a, $players, $from, $to, [$on => $spell], $healer['guid'] ?? null, $me),
                'hp' => $hp,
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
        $theirGoes = array_values(array_filter($a['goes'] ?? [], fn ($g) => $g['side'] !== $me['side']));
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
        foreach ($a['goes'] ?? [] as $go) {
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
                'title' => "Enemy go on {$target}".($killed ? ': it killed' : ''),
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

    /** name => cooldown, every offensive or mixed cooldown in the classification of BIG_COOLDOWN or more. */
    private function bigOffensives(): array
    {
        if ($this->bigOff !== null) {
            return $this->bigOff;
        }
        $this->bigOff = [];
        foreach (['offensive-spells.json', 'offensive-buffs.json', 'mixed-cooldowns.json'] as $file) {
            foreach (json_decode((string) @file_get_contents(base_path('data/arena-logs/spell-classification/'.$file)), true) ?: [] as $e) {
                if (($e['cooldownSeconds'] ?? 0) >= self::BIG_COOLDOWN) {
                    $this->bigOff[$e['name']] = (int) $e['cooldownSeconds'];
                }
            }
        }

        return $this->bigOff;
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
                $out[] = $raw ? $c + [2 => null] : $c[1];
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

    private function secs(float $s, int $decimals = 1): string
    {
        return number_format(max(0, $s), $decimals).'s';
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
