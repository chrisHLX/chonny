<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;

/**
 * The moments in a round: when somebody committed, and what happened because they did.
 *
 * THE MOMENTS ARE FOUND FROM THE COOLDOWNS, NOT FROM A CLOCK. An earlier pass looked for a health
 * collapse inside a fixed window, which needed a threshold nobody could defend — at 35% it fired
 * every twelve seconds on ordinary churn, and at 85% it was arbitrary in the other direction. A
 * round does not have evenly spaced interesting bits. It has the instants when somebody spent
 * something they cannot spend again for a minute, and everything worth saying is arranged around
 * those. So: cluster the significant casts, let the cluster define the window, and measure the
 * damage, control and health inside it.
 *
 * WHAT COUNTS AS SIGNIFICANT. A real cooldown, and only that — {@see MIN_COOLDOWN_SECONDS} against
 * `spells.cooldown_seconds`, which is complete and patch-current. The hand-reviewed classification
 * is a partial list of 304 ids and is used ONLY to label which way a cast points, never to decide
 * that a cast matters. Letting it decide put a Priest's Penance — a nine-second rotational spell
 * that happens to be listed — in as a commitment, and produced "moments" that were one player
 * casting Penance.
 *
 * MIXED IS NOT DEFENSIVE. `mixed-cooldowns.json` holds 25 spells that are both — Avatar,
 * Metamorphosis, Bestial Wrath, Ultimate Penitence. Reading "mixed" as "defensive" put Avatar in
 * the list of things a Warrior pressed to survive, which is close to the opposite of what it is.
 * It is carried as its own kind and counted as commitment.
 *
 * A MOMENT IS NOT ONE-SIDED. Both teams commit in the same window all the time — that is a trade,
 * and splitting it into two moments loses the fact that they happened together. `committed` is per
 * side, and `kind` says whether one side or both spent something.
 *
 * Read-only, and it takes plain log lines so it can be tested without an archive or a match.
 */
class ArenaMomentService
{
    /**
     * A cast has to be off the table for at least this long to be worth listing at all. Below it a
     * spell is rotational — a Priest's Penance is on 9 seconds and is not a decision.
     */
    public const MIN_COOLDOWN_SECONDS = 45;

    /**
     * ONLY A COOLDOWN THIS LONG ANCHORS A MOMENT.
     *
     * Everything above MIN is worth listing; only these decide where a moment begins and ends. The
     * distinction exists because without it a busy round is one moment: somebody presses something
     * on a 45-second cooldown every few seconds, the chain never breaks, and a real round came out
     * as a single 44-second window holding 38 commitments, which is a description of the round
     * rather than of anything in it. Avatar, Combustion, Recklessness and Avenging Wrath anchor;
     * Colossus Smash and a trinket ride along.
     */
    public const ANCHOR_COOLDOWN_SECONDS = 90;

    /**
     * A lull between ANCHORS longer than this ends the moment. Three globals of nobody spending
     * anything real is not one sequence — it is the gap between two of them.
     */
    public const MOMENT_GAP_SECONDS = 5.0;

    /**
     * How long after the last commitment a moment keeps listening. A go's damage lands after the
     * buttons are pressed, and the kill often arrives here rather than during.
     */
    public const MOMENT_TAIL_SECONDS = 6.0;

    /** Control that damage can end. A stun is not broken by a Shadow Word: Pain tick. */
    private const BREAKABLE = ['Incapacitate', 'Disorient'];

    private const DAMAGE_EVENTS = [
        'SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE', 'SWING_DAMAGE_LANDED',
    ];

    private ?array $cooldowns = null;

    private ?array $crowdControl = null;

    public function __construct(private ArenaLogService $arena) {}

    /**
     * @param  array<int, string>  $lines  one round's raw log lines
     * @param  array  $metadata  that round's derived metadata (for the roster and sides)
     * @return array<int, array<string, mixed>>
     */
    public function detect(array $lines, array $metadata): array
    {
        $roster = $this->roster($metadata);

        if ($roster === []) {
            return [];
        }

        $timeline = $this->readTimeline($lines, $roster);

        if ($timeline['commitments'] === []) {
            return [];
        }

        $anchors = array_values(array_filter(
            $timeline['commitments'],
            fn ($c) => $c['cooldown'] >= self::ANCHOR_COOLDOWN_SECONDS
        ));

        if ($anchors === []) {
            return [];
        }

        $moments = [];

        foreach ($this->cluster($anchors) as $cluster) {
            $moments[] = $this->describe($cluster, $timeline, $roster);
        }

        return $moments;
    }

    // ------------------------------------------------------------------ reading

    /** @return array<string, array{name: string, side: mixed, healer: bool, spec: string}> */
    private function roster(array $metadata): array
    {
        $roster = [];

        foreach ($metadata['units'] ?? [] as $unit) {
            if (! str_starts_with($unit['id'] ?? '', 'Player-') || ($unit['spec'] ?? '0') === '0') {
                continue;
            }

            $spec = $this->arena->specForExternalId((string) $unit['spec']);

            $roster[$unit['id']] = [
                'name' => $unit['name'] ?? '?',
                // `reaction` is friendly-or-hostile as seen by the logging player. That is exactly
                // the two-sided split this needs, and explicitly NOT the arena team id.
                'side' => $unit['reaction'] ?? null,
                'spec' => $spec?->name ?? '?',
                'healer' => $spec !== null && $this->arena->isHealerSpec(
                    $spec->gameClass?->slug ?? '', $spec->slug ?? ''
                ),
            ];
        }

        return $roster;
    }

    private function readTimeline(array $lines, array $roster): array
    {
        $cooldowns = $this->cooldowns();
        $cc = $this->crowdControl();
        $classification = $this->arena->offensiveDefensiveClassification();

        $commitments = [];
        $damage = [];
        $health = [];
        $control = [];
        $deaths = [];
        $open = [];
        $t0 = null;

        foreach ($lines as $line) {
            $stamp = $this->seconds($line);

            if ($stamp === null) {
                continue;
            }

            $t0 ??= $stamp;
            $t = round($stamp - $t0, 2);
            $body = explode('  ', $line, 2)[1] ?? '';
            $event = strtok($body, ',');

            if ($event === false) {
                continue;
            }

            $isDamage = in_array($event, self::DAMAGE_EVENTS, true);

            if (! $isDamage && ! in_array($event, ['SPELL_CAST_SUCCESS', 'SPELL_AURA_APPLIED', 'SPELL_AURA_REMOVED', 'UNIT_DIED'], true)) {
                continue;
            }

            $f = str_getcsv($body);
            $src = $f[1] ?? '';
            $dst = $f[5] ?? '';
            $spellId = (int) ($f[9] ?? 0);
            $spellName = (string) ($f[10] ?? '');

            if ($event === 'UNIT_DIED') {
                // The trailing field is `unconsciousOnDeath`: 1 is a feign, 0 a real death.
                if (array_key_exists($dst, $roster) && (string) end($f) === '0') {
                    $deaths[] = ['t' => $t, 'who' => $dst];
                }

                continue;
            }

            if ($event === 'SPELL_CAST_SUCCESS') {
                if (! array_key_exists($src, $roster)) {
                    continue;
                }

                if (! isset($cooldowns[$spellId])) {
                    continue;
                }

                // One press can log twice (Power Infusion on self and on an ally is the common
                // case). Counting it twice inflates how much a side committed.
                $last = end($commitments);

                if ($last !== false && $last['who'] === $src && $last['spell'] === $spellName && $t - $last['t'] < 1.0) {
                    continue;
                }

                $commitments[] = [
                    't' => $t, 'who' => $src, 'spell' => $spellName, 'spellId' => $spellId,
                    'cooldown' => (float) $cooldowns[$spellId],
                    'kind' => $this->kindOf($spellName, $spellId, $classification) ?? 'cooldown',
                ];

                continue;
            }

            if ($event === 'SPELL_AURA_APPLIED') {
                if (array_key_exists($dst, $roster) && isset($cc[$spellId])) {
                    $open["{$dst}|{$spellId}"] = ['t' => $t, 'by' => $src, 'spell' => $spellName];
                }

                continue;
            }

            if ($event === 'SPELL_AURA_REMOVED') {
                $key = "{$dst}|{$spellId}";

                if (! isset($open[$key])) {
                    continue;
                }

                $control[] = [
                    'on' => $dst, 'by' => $open[$key]['by'], 'spell' => $spellName,
                    'dr' => $cc[$spellId], 'from' => $open[$key]['t'], 'to' => $t,
                ];
                unset($open[$key]);

                continue;
            }

            $isSwing = str_starts_with($event, 'SWING');
            $amount = $f[count($f) - ($isSwing ? 10 : 11)] ?? null;

            if (! is_numeric($amount) || ! array_key_exists($dst, $roster)) {
                continue;
            }

            // Self-damage is not output. A health-cost ability otherwise counts toward its own
            // team's damage and puts a peak on the wrong side of the scoreboard.
            if ($src !== $dst) {
                $damage[] = ['t' => $t, 'src' => $src, 'dst' => $dst, 'amount' => (int) $amount];
            }

            // The advanced-parameter block describes the event's TARGET, so currentHP/maxHP there
            // is the victim's — a health curve read straight off the log.
            $base = $isSwing ? 9 : 12;
            $current = $f[$base + 2] ?? null;
            $max = $f[$base + 3] ?? null;

            if (is_numeric($current) && is_numeric($max) && $max > 0) {
                $health[$dst][] = ['t' => $t, 'pct' => round(100 * $current / $max, 1)];
            }
        }

        return compact('commitments', 'damage', 'health', 'control', 'deaths');
    }

    /** offensive / defensive / mixed, or null when the classification has never seen it. */
    private function kindOf(string $name, int $spellId, array $classification): ?string
    {
        $entry = $classification['bySpellId'][$spellId]
            ?? $classification['byName'][strtolower($name)]
            ?? null;

        if ($entry === null) {
            return null;
        }

        $offensive = (bool) ($entry['offensive'] ?? false);
        $defensive = (bool) ($entry['defensive'] ?? false);

        return match (true) {
            $offensive && $defensive => 'mixed',
            $offensive => 'offensive',
            $defensive => 'defensive',
            default => null,
        };
    }

    // ------------------------------------------------------------------ clustering

    /** @return array<int, array<int, array>> */
    private function cluster(array $commitments): array
    {
        usort($commitments, fn ($a, $b) => $a['t'] <=> $b['t']);

        $clusters = [];
        $current = [];

        foreach ($commitments as $c) {
            if ($current !== [] && $c['t'] - end($current)['t'] > self::MOMENT_GAP_SECONDS) {
                $clusters[] = $current;
                $current = [];
            }

            $current[] = $c;
        }

        if ($current !== []) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    // ------------------------------------------------------------------ describing

    private function describe(array $cluster, array $timeline, array $roster): array
    {
        $from = $cluster[0]['t'];
        $to = end($cluster)['t'] + self::MOMENT_TAIL_SECONDS;

        // Who spent what, per side — every commitment inside the window, not just the anchors
        // that defined it, because the supporting presses are what make the shape readable.
        $committed = [];

        $inside = array_values(array_filter(
            $timeline['commitments'],
            fn ($c) => $c['t'] >= $from && $c['t'] <= $to
        ));

        foreach ($inside as $c) {
            $side = $roster[$c['who']]['side'];
            $committed[$side][] = [
                'at' => round($c['t'] - $from, 1),
                'who' => $roster[$c['who']]['name'],
                'spell' => $c['spell'],
                'kind' => $c['kind'],
            ];
        }

        // Damage inside the window, by attacker side and by victim.
        $dealt = [];
        $taken = [];

        foreach ($timeline['damage'] as $d) {
            if ($d['t'] < $from || $d['t'] > $to) {
                continue;
            }

            $dealt[$roster[$d['src']]['side'] ?? 'other'] = ($dealt[$roster[$d['src']]['side'] ?? 'other'] ?? 0) + $d['amount'];
            $taken[$d['dst']] = ($taken[$d['dst']] ?? 0) + $d['amount'];
        }

        arsort($taken);
        $primary = array_key_first($taken);

        // The pressured player's health across the window.
        $pressure = null;

        if ($primary !== null) {
            $points = array_values(array_filter(
                $timeline['health'][$primary] ?? [],
                fn ($p) => $p['t'] >= $from && $p['t'] <= $to
            ));

            if ($points !== []) {
                $pressure = [
                    'who' => $roster[$primary]['name'],
                    'spec' => $roster[$primary]['spec'],
                    'side' => $roster[$primary]['side'],
                    'from' => $points[0]['pct'],
                    'low' => min(array_column($points, 'pct')),
                    'damageTaken' => $taken[$primary],
                ];
            }
        }

        // Control on a healer, and whether the caster's own team removed it.
        $onHealer = [];

        foreach ($timeline['control'] as $c) {
            if ($c['from'] > $to || $c['to'] < $from || ! ($roster[$c['on']]['healer'] ?? false)) {
                continue;
            }

            // A totem or a pet can apply control — Capacitor Totem is the common one — and it is
            // not in the roster, so there is no side to attribute it to. Without a side there is
            // no such thing as "the caster's own damage", so the break attribution is skipped
            // rather than guessed. Before this guard, an unknown caster matched every other
            // unknown damage source and the name lookup threw.
            $casterSide = $roster[$c['by']]['side'] ?? null;
            $brokenBy = [];

            if ($casterSide !== null && in_array($c['dr'], self::BREAKABLE, true)) {
                foreach ($timeline['damage'] as $d) {
                    if ($d['dst'] !== $c['on'] || $d['t'] <= $c['from'] + 0.05 || $d['t'] >= $c['to']) {
                        continue;
                    }
                    if (! isset($roster[$d['src']]) || $roster[$d['src']]['side'] !== $casterSide) {
                        continue;
                    }
                    $brokenBy[$roster[$d['src']]['name']] = true;
                }
            }

            $onHealer[] = [
                'on' => $roster[$c['on']]['name'],
                'by' => $roster[$c['by']]['name'] ?? 'a pet or totem',
                'bySide' => $casterSide,
                'spell' => $c['spell'],
                'dr' => $c['dr'],
                'held' => round($c['to'] - $c['from'], 1),
                // Named rather than a boolean: "your own Shadow Word: Pain" is the actionable part.
                'brokenBy' => array_keys($brokenBy),
            ];
        }

        $deaths = [];

        foreach ($timeline['deaths'] as $d) {
            if ($d['t'] >= $from && $d['t'] <= $to) {
                $deaths[] = $roster[$d['who']]['name'];
            }
        }

        $sidesCommitting = array_keys($committed);

        return [
            'from' => round($from, 1),
            'to' => round($to, 1),
            'seconds' => round($to - $from, 1),
            'kind' => count($sidesCommitting) > 1 ? 'trade' : 'one-sided',
            'committed' => $committed,
            'damageBySide' => $dealt,
            'pressure' => $pressure,
            'controlOnHealer' => $onHealer,
            'deaths' => $deaths,
        ];
    }

    // ------------------------------------------------------------------ support

    private function cooldowns(): array
    {
        return $this->cooldowns ??= Spell::query()
            ->where('patch_id', Patch::where('is_current', true)->value('id'))
            ->where('cooldown_seconds', '>=', self::MIN_COOLDOWN_SECONDS)
            ->pluck('cooldown_seconds', 'spell_id')
            ->all();
    }

    private function crowdControl(): array
    {
        return $this->crowdControl ??= Spell::query()
            ->where('patch_id', Patch::where('is_current', true)->value('id'))
            ->whereNotNull('dr_category')
            ->pluck('dr_category', 'spell_id')
            ->all();
    }

    /** WoW stamps `M/D/YYYY HH:MM:SS.ssss` with no zone; only the difference matters here. */
    private function seconds(string $line): ?float
    {
        if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
            return null;
        }

        return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3])
            + (float) ('0.'.$m[7]);
    }
}
