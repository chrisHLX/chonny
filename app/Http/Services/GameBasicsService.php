<?php

namespace App\Http\Services;

use App\Console\Commands\BuildPopulation;
use App\Models\Specialization;
use App\Models\Spell;
use App\Quiz\Wow\WowAbilityFacts;
use Illuminate\Support\Facades\File;

/**
 * The basics of one game (or a shuffle lobby), for the player who logged it (2026-10-06): did the
 * team play the go the way that kills, what did your crowd control and your casts run into, how
 * your output compares with every player of your spec, and what your failed casts say about
 * positioning and macros.
 *
 * Every line rests on a measured relationship across the whole archive (wow:population, 795 games,
 * 1,193 players), not on the player's own games alone, and only the ones that held are here
 * (docs/learning/population-findings-2026-10-06.md):
 *  - control on their healer during your go: goes with none killed 19%, with four or more 38%;
 *    their healer locked 4s+ in your burst 36% against 24% with none, kicked in it 45%;
 *  - your damage landing together (the go's peak shared): 34% with their healer locked against 23%
 *    with neither. Pressing within a few seconds did NOT predict kills on its own, so the read is
 *    the damage overlap, not the button timing;
 *  - a healer crowd-controlled more than most healers of the spec won 40% of rounds, less 61%;
 *  - rounds with many "not in line of sight" failures won 45% against 55%, with casts interrupted
 *    46% against 53%.
 * Correlations, said as such: a lost round is also why a healer was locked.
 *
 * Reads only the stored analysis (RoundAnalysisService version 10, `habits`) and the committed norms
 * (data/population/norms.json), so it runs where the raw log is gone (the website).
 */
class GameBasicsService
{
    /** Below this share of the spec median, your output is flagged; at or above the median it is a strength. */
    private const LOW_OUTPUT = 0.7;

    /** A button pressed under this share of the spec median, by a spec that mostly presses it, is named. */
    private const UNDER_PRESSED = 0.6;

    /** Failures of one kind before they are worth a line (per game; a shuffle lobby sums its rounds). */
    private const FAIL_MIN = 3;

    /** Positioning failures and what to do about them. Spells are filled in from the game. */
    private const POSITIONING = [
        'Target not in line of sight' => 'Not in line of sight',
        'Out of range' => 'Out of range',
        'Target needs to be in front of you.' => 'Not facing the target',
        "Can't do that while moving" => 'Cast while moving',
    ];

    private ?array $norms = null;

    /** @var array<int, array<string, true>> spec id => names the spec can press */
    private array $pressable = [];

    /**
     * @param  array<int, array>  $analyses  the stored analyses of one game's rounds (one for 2v2/3v3)
     * @return ?array{sections: array, spec: ?string, rounds: int}
     */
    public function forRounds(array $analyses): ?array
    {
        $analyses = array_values(array_filter($analyses, fn ($a) => ($a['version'] ?? 0) >= 10 && isset($a['habits'])));
        if ($analyses === []) {
            return null;
        }

        $me = collect($analyses[0]['players'])->firstWhere('logger', true);
        if (! $me) {
            return null;
        }

        $sections = array_values(array_filter([
            $this->coordination($analyses),
            $this->trades($analyses),
            $this->control($analyses, $me['guid']),
            $this->output($analyses, $me),
            $this->positioning($analyses, $me['guid']),
        ]));

        return $sections ? ['sections' => $sections, 'spec' => $me['spec'] ?? null, 'rounds' => count($analyses)] : null;
    }

    // ------------------------------------------------------------------ coordination

    private function coordination(array $analyses): ?array
    {
        $goes = collect($analyses)->flatMap(fn ($a) => array_values(array_filter($a['goes'], fn ($g) => $g['side'] === 'us')));
        if ($goes->isEmpty()) {
            return null;
        }

        $n = $goes->count();
        $locked = $goes->filter(fn ($g) => ($g['peak']['healerLocked'] ?? 0) >= 2 || ($g['peak']['healerKicked'] ?? false))->count();
        $healerCc = $goes->sum(fn ($g) => (int) ($g['healerCc'] ?? 0));
        $joint = $goes->filter(fn ($g) => $g['peak']['joint'] ?? false)->count();
        $jointLocked = $goes->filter(fn ($g) => ($g['peak']['joint'] ?? false) && (($g['peak']['healerLocked'] ?? 0) >= 2 || ($g['peak']['healerKicked'] ?? false)))->count();
        // Their defensives already on cooldown as the go started (the answer pool, brain.md
        // {#answer-pool}): the strongest go-level effect in the archive that held at every stage of
        // a game, not just late ones.
        $intoDown = $goes->filter(fn ($g) => ($g['drained'] ?? 0) >= 1)->count();

        // Control that reached their healer in most goes but missed the peak is a timing lesson, not
        // a "use more control" one: the damage and the control went out at different moments.
        $mistimed = $healerCc / $n >= 1.5 && $locked * 2 < $n;

        return [
            'title' => 'Your team\'s goes',
            'rows' => [
                [
                    'label' => 'Their healer locked during your burst',
                    'value' => "{$locked} of {$n}",
                    'tone' => $locked * 2 >= $n ? 'good' : 'warn',
                    'text' => ($mistimed
                        ? 'Your control did reach their healer, but your damage peaked while she was free in most goes: press the cooldowns as the control lands, not before it or after it.'
                        : 'Crowd control or a kick on their healer in the six seconds your damage peaked.')
                        .$this->evidence('healerLockedInPeak', ' Across the archive, goes like that killed {with}% of the time, against {without}% with her free.'),
                ],
                [
                    'label' => 'Crowd control on their healer per go',
                    'value' => $n ? number_format($healerCc / $n, 1) : '0',
                    'tone' => $healerCc / $n >= 3 ? 'good' : ($healerCc / $n >= 1.5 ? 'neutral' : 'warn'),
                    'text' => 'The more of the go their healer spends controlled, the more goes kill.'.$this->evidence('healerControlDose', ' In the archive, goes with none killed {without}% of the time, with four or more {with}%.'),
                ],
                [
                    'label' => 'Goes started with their defensives already down',
                    'value' => "{$intoDown} of {$n}",
                    'tone' => $intoDown * 2 >= $n ? 'good' : 'neutral',
                    'text' => 'At least one of their defensives on cooldown as your go began. A go into a full set of answers mostly strips them; the kill comes from the go after.'
                        .$this->evidence('theirDefensivesDown', ' In the archive, goes like that killed {with}% of the time, against {without}% into a full set, and it held at every stage of a game.'),
                ],
                [
                    'label' => 'Damage landed together, with their healer locked',
                    'value' => "{$jointLocked} of {$n}",
                    'tone' => $jointLocked * 2 >= $n ? 'good' : ($jointLocked > 0 || $joint > 0 ? 'neutral' : 'warn'),
                    'text' => "Both damage dealers' damage in the same six seconds while their healer could not act: the go that kills most".$this->evidence('jointWithHealerLocked', ' ({with}% against {without}% with neither)').". Yours shared the peak in {$joint} of {$n}.",
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ trading (their goes on you)

    /**
     * Your team's answers to their goes (2026-10-07). A trade is judged by what is still up when
     * their NEXT go comes, not by how many answers a go drew: within a rating band the side that
     * spent more per go lost more, and the count drawn did not predict a kill
     * (docs/learning/population-findings-2026-10-07-trading.md). So the line that matters is how
     * many of their goes met your big defensives already down (the go's `cover`, the cooldown
     * ledger), and the answers-per-go figure is shown as a fact, never graded.
     */
    private function trades(array $analyses): ?array
    {
        $theirs = collect($analyses)->flatMap(fn ($a) => array_values(array_filter($a['goes'], fn ($g) => $g['side'] === 'them')));
        if ($theirs->isEmpty()) {
            return null;
        }

        $n = $theirs->count();
        $covered = $theirs->filter(fn ($g) => isset($g['cover']['bigDown']));
        $twoDown = $covered->filter(fn ($g) => $g['cover']['bigDown'] >= 2)->count();
        $perGo = $theirs->sum(fn ($g) => (int) ($g['defs'] ?? 0)) / $n;

        $rows = [];
        if ($covered->isNotEmpty()) {
            $rows[] = [
                'label' => 'Their goes that met two or more of your big defensives down',
                'value' => "{$twoDown} of {$covered->count()}",
                'tone' => $twoDown === 0 ? 'good' : ($twoDown * 3 >= $covered->count() ? 'warn' : 'neutral'),
                'text' => 'A trade is good when an answer is back for their next go. In your own games, their go killed 35–43% of the time with two or more big defensives down, 17–22% with one.'
                    .$this->evidence('theirDefensivesDown', ' Across the archive, goes into defensives already down killed {with}% against {without}%.'),
            ];
        }
        $rows[] = [
            'label' => 'Answers your team spent per go of theirs',
            'value' => number_format($perGo, 1),
            'tone' => 'neutral',
            'text' => 'Not graded: stronger teams spend more because stronger goes demand it, and within a rating band the sides spending more per go won less. Spend one answer a go when they go often, and keep the long ones for the go that comes with their big cooldowns; the Matchup Lab shows the plan for a matchup.',
        ];

        return ['title' => 'Your answers to their goes', 'rows' => $rows];
    }

    // ------------------------------------------------------------------ your crowd control and casts

    private function control(array $analyses, string $me): ?array
    {
        $roles = ['healer' => 0, 'target' => 0, 'cross' => 0];
        $offTarget = 0;
        $control = 0;
        $kicked = [];
        foreach ($analyses as $a) {
            foreach ($a['goes'] as $g) {
                foreach ($g['links'] ?? [] as $l) {
                    if (($l['by'] ?? null) === $me && $l['cat'] === 'control' && isset($roles[$l['role'] ?? ''])) {
                        $roles[$l['role']]++;
                    }
                }
            }
            $h = $a['habits'][$me] ?? [];
            $offTarget += $h['offTarget'] ?? 0;
            $control += $h['control'] ?? 0;
            foreach ($h['kicked'] ?? [] as $spell => $k) {
                $kicked[$spell] = ($kicked[$spell] ?? 0) + $k;
            }
        }
        arsort($kicked);

        $rows = [];
        if (array_sum($roles) > 0) {
            $rows[] = [
                'label' => 'Where your crowd control went in your goes',
                'value' => "{$roles['healer']} healer · {$roles['target']} kill target · {$roles['cross']} other",
                'tone' => 'neutral',
                'text' => 'Their healer is where control turns a go into a kill; stuns that hold through damage are the ones to spend on the kill target.',
            ];
        }
        $kickedTotal = array_sum($kicked);
        if ($kickedTotal > 0) {
            $top = collect($kicked)->take(3)->map(fn ($n, $s) => "{$s} ×{$n}")->implode(', ');
            $rows[] = [
                'label' => 'Your casts that were kicked',
                'value' => (string) $kickedTotal,
                'tone' => $kickedTotal >= self::FAIL_MIN ? 'warn' : 'neutral',
                'text' => "{$top}. Start the casts that matter when their kicker has just used their kick, or start one and stop it to draw the kick out.".$this->evidence('castsInterrupted', ' Rounds with many casts interrupted won {with}% against {without}% in the archive.'),
                'spells' => array_keys(array_slice($kicked, 0, 3, true)),
            ];
        }
        if ($control >= 3 && $offTarget * 2 >= $control) {
            $spell = $this->offTargetSpell($analyses, $me);
            $rows[] = [
                'label' => 'Control and kicks on someone you were not hitting',
                'value' => "{$offTarget} of {$control}",
                'tone' => 'neutral',
                'text' => 'Each of those is two target swaps unless it is on a macro: a focus macro, or one per arena frame, casts it on their healer while you keep your target.'.$this->evidence('offTargetControl', ' Players who put most of their control on someone other than their target won {with}% of rounds against {without}%; a macro makes that one button.'),
                'macro' => $spell,
            ];
        }

        return $rows ? ['title' => 'Your crowd control and casts', 'rows' => $rows] : null;
    }

    /** Your single-target control most often put on someone other than the go's target. */
    private function offTargetSpell(array $analyses, string $me): ?string
    {
        $count = [];
        foreach ($analyses as $a) {
            foreach ($a['goes'] as $g) {
                foreach ($g['links'] ?? [] as $l) {
                    if (($l['by'] ?? null) === $me && $l['cat'] === 'control' && in_array($l['role'] ?? null, ['healer', 'cross'], true) && ($l['on'] ?? null)) {
                        $count[$l['spell']] = ($count[$l['spell']] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($count);

        return array_key_first($count);
    }

    // ------------------------------------------------------------------ output against the spec

    private function output(array $analyses, array $me): ?array
    {
        $spec = (string) ($me['specExternalId'] ?? '');
        $norm = $this->norms()['specs'][$spec] ?? null;
        if (! $norm) {
            return null;
        }

        $free = 0.0;
        $alive = 0.0;
        $idle = 0.0;
        $amount = 0;
        $locked = 0.0;
        $casts = [];
        foreach ($analyses as $a) {
            $h = $a['habits'][$me['guid']] ?? [];
            $b = $a['breakdown'][$me['guid']] ?? [];
            $free += $h['free'] ?? 0;
            $alive += $b['alive'] ?? 0;
            $idle += $b['idle'] ?? 0;
            $locked += $a['lockout'][$me['guid']] ?? 0;
            $kinds = $norm['healer'] ? ['healing', 'absorbs'] : ['damage'];
            foreach ($kinds as $k) {
                $amount += array_sum(array_column($b[$k] ?? [], 'amount'));
            }
            foreach ($h['casts'] ?? [] as $name => $n) {
                $casts[$name] = ($casts[$name] ?? 0) + $n;
            }
        }
        if ($free < 60) {
            return null;
        }

        $minutes = $free / 60;
        $rows = [];
        $label = $norm['label'];

        if ($norm['healer']) {
            // Healing output is not a skill read (a healer heals more on a losing team), so a healer's
            // line is time controlled, the habit most tied to winning for a healer.
            $share = $locked / max(1, $alive);
            $p50 = $norm['lockedShare']['p50'] ?? null;
            if ($p50 !== null) {
                $rows[] = [
                    'label' => 'Time you spent crowd controlled',
                    'value' => round($share * 100).'% (most '.$label.'s '.round($p50 * 100).'%)',
                    'tone' => $share > ($norm['lockedShare']['p75'] ?? 1) ? 'warn' : ($share <= $p50 ? 'good' : 'neutral'),
                    'text' => $this->evidence('healerControlledLess', 'Across every healer in the archive, a healer controlled more than most of their spec won {without}% of rounds, less {with}%. ')
                        .'Line of sight their casters, stand out of melee stun range, and keep the Medallion for the control that would lose the round.',
                ];
            }
        } else {
            $dpm = $amount / $minutes;
            $p50 = $norm['damagePerFreeMinute']['p50'] ?? null;
            if ($p50) {
                $ratio = $dpm / $p50;
                $rows[] = [
                    'label' => 'Damage per minute free to act',
                    'value' => $this->short($dpm).' ('.round($ratio * 100).'% of the median '.$label.')',
                    'tone' => $ratio >= 1 ? 'good' : ($ratio < self::LOW_OUTPUT ? 'warn' : 'neutral'),
                    'text' => 'Counted only while you were alive and not controlled, so being stunned or kicked does not count against you.',
                ];
            }
        }

        $idleShare = $idle / max(1, $alive);
        $idleP50 = $norm['idleShare']['p50'] ?? null;
        if (! $norm['healer'] && $idleP50 !== null && $idleShare > ($norm['idleShare']['p75'] ?? 1)) {
            $rows[] = [
                'label' => 'Time not pressing anything',
                'value' => round($idleShare * 100).'% (most '.$label.'s '.round($idleP50 * 100).'%)',
                'tone' => 'warn',
                'text' => 'Gaps with nothing pressed while you were free to act.'.$this->evidence('dpsIdleLess', ' Damage dealers idle more than most of their spec won {without}% of rounds, against {with}%.'),
            ];
        }

        $under = $this->underPressed($casts, $minutes, $norm, (int) $spec);
        if ($under) {
            $rows[] = [
                'label' => 'Buttons you pressed less than most '.$label.'s',
                'value' => implode(' · ', array_map(fn ($u) => "{$u['name']} {$u['mine']} a minute (most {$u['p50']})", $under)),
                'tone' => 'warn',
                'text' => 'Per minute free to act, against the median player of your spec in the archive. Usually a button forgotten in the rotation, or saved too long.',
                'spells' => array_column($under, 'name'),
            ];
        }

        return $rows ? ['title' => 'Your output against every '.$label, 'rows' => $rows, 'source' => $norm['rounds'].' rounds of '.$label.'s, '.$norm['players'].' players'] : null;
    }

    /** Pressable buttons the spec presses in most rounds, that you pressed well under its median. */
    private function underPressed(array $casts, float $minutes, array $norm, int $spec): array
    {
        $pressable = $this->pressable($spec);
        $out = [];
        foreach ($norm['abilities'] ?? [] as $name => $a) {
            if (str_starts_with($name, 'pet: ') || ($a['used'] ?? 0) < 0.6 || ($a['p50'] ?? 0) < 0.5 || ($pressable && ! isset($pressable[$name]))) {
                continue;
            }
            // Never pressed at all is most often a talent not taken (Feral's Moonfire needs Lunar
            // Inspiration), which the casts cannot tell from a forgotten button: only a button the
            // player did press, and pressed rarely, is named.
            if (($casts[$name] ?? 0) === 0) {
                continue;
            }
            $mine = $casts[$name] / $minutes;
            if ($mine < self::UNDER_PRESSED * $a['p50']) {
                $out[] = ['name' => $name, 'mine' => round($mine, 1), 'p50' => round($a['p50'], 1), 'gap' => $mine / $a['p50']];
            }
        }
        usort($out, fn ($x, $y) => $x['gap'] <=> $y['gap']);

        return array_slice($out, 0, 2);
    }

    // ------------------------------------------------------------------ positioning and macros

    private function positioning(array $analyses, string $me): ?array
    {
        $failed = [];
        $pet = [0, 0];
        foreach ($analyses as $a) {
            $h = $a['habits'][$me] ?? [];
            foreach ($h['failed'] ?? [] as $why => $r) {
                $failed[$why]['n'] = ($failed[$why]['n'] ?? 0) + $r['n'];
                foreach ($r['spells'] as $s => $n) {
                    $failed[$why]['spells'][$s] = ($failed[$why]['spells'][$s] ?? 0) + $n;
                }
            }
            $pet[0] += $h['petOnTarget'] ?? 0;
            $pet[1] += $h['petHits'] ?? 0;
        }

        $rows = [];
        foreach (self::POSITIONING as $why => $label) {
            $f = $failed[$why] ?? null;
            if (! $f || $f['n'] < self::FAIL_MIN) {
                continue;
            }
            arsort($f['spells']);
            $top = collect($f['spells'])->take(3)->map(fn ($n, $s) => "{$s} ×{$n}")->implode(', ');
            $rows[] = [
                'label' => $label,
                'value' => (string) $f['n'],
                'tone' => 'warn',
                'text' => $top.'. '.$this->positionTip($why),
                'spells' => array_keys(array_slice($f['spells'], 0, 3, true)),
            ];
        }

        $trinket = $failed['Item is not ready yet']['n'] ?? 0;
        if ($trinket > 0) {
            $rows[] = [
                'label' => 'Medallion pressed while it was down',
                'value' => (string) $trinket,
                'tone' => 'warn',
                'text' => 'Track it: when it is down, the next stun has to be answered another way (a defensive, line of sight, or your healer).'.$this->evidence('trinketNotReady', ' Rounds with this won {with}% against {without}%, which is mostly the round going badly already.'),
            ];
        }

        if ($pet[1] >= 30 && $pet[0] / $pet[1] < 0.6) {
            $rows[] = [
                'label' => 'Your pet on your target',
                'value' => round($pet[0] / $pet[1] * 100).'% of its hits',
                'tone' => 'neutral',
                'text' => 'A pet left on another target is damage outside your go. Put /petattack in the macro of your main attack.',
            ];
        }

        return $rows ? ['title' => 'Positioning and macros', 'rows' => $rows, 'source' => 'Failed casts as WoW logged them for you'] : null;
    }

    private function positionTip(string $why): string
    {
        return match ($why) {
            'Target not in line of sight' => 'Move before you press: a pillar between you and the target blocks heals and control alike.'.$this->evidence('lineOfSightFailures', ' Rounds with many of these won {with}% against {without}%.'),
            'Out of range' => 'Close the distance before the go starts; a stun pressed from too far is a go that starts late.',
            'Target needs to be in front of you.' => 'Face the target before pressing; a strafe key held during a cast keeps you facing.',
            "Can't do that while moving" => 'Stop for the cast, or use an instant while you move.',
            default => '',
        };
    }

    // ------------------------------------------------------------------ data

    /**
     * A sentence from the evidence table wow:population recomputes on every run (norms.json,
     * `evidence`): {with} and {without} become the two rates as whole percentages. Empty when the
     * archive has no answer yet, so a line never states a number the data does not hold.
     */
    private function evidence(string $key, string $sentence): string
    {
        $e = $this->norms()['evidence'][$key] ?? null;
        $with = $e['with']['rate'] ?? null;
        $without = $e['without']['rate'] ?? null;
        if ($with === null || $without === null || ($e['with']['n'] ?? 0) < 20 || ($e['without']['n'] ?? 0) < 20) {
            return '';
        }

        return strtr($sentence, ['{with}' => (string) round($with * 100), '{without}' => (string) round($without * 100)]);
    }

    /** How many games the norms were built from, for the page's footnote. */
    public static function archiveGames(): int
    {
        $path = base_path(BuildPopulation::NORMS);

        return File::exists($path) ? (int) (json_decode(File::get($path), true)['games'] ?? 0) : 0;
    }

    private function norms(): array
    {
        $path = base_path(BuildPopulation::NORMS);

        return $this->norms ??= File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
    }

    /**
     * The spec's ROTATION buttons, from its precomputed kit, so a "pressed less" line never names a
     * proc or a passive (Soul Fragment is "cast" 900 times a game by nobody's hands), nor a button
     * pressed when the game asks for it: a defensive, crowd control, a kick, a movement spell or the
     * Medallion (Blessing of Sacrifice "pressed less than most" is not a fault). Empty when no kit is
     * on disk, which lets every name through.
     *
     * @return array<string, true>
     */
    private function pressable(int $externalSpecId): array
    {
        if (isset($this->pressable[$externalSpecId])) {
            return $this->pressable[$externalSpecId];
        }

        $spec = Specialization::with('gameClass')->where('external_spec_id', $externalSpecId)->first();
        $path = $spec ? base_path("data/spell-kits/{$spec->gameClass?->slug}/{$spec->slug}.json") : null;
        $entries = $path && File::exists($path) ? collect(json_decode(File::get($path), true)['entries'] ?? []) : collect();
        $situational = fn (array $e) => $e['drCategory'] !== null
            || (($e['offensiveDefensive']['defensive'] ?? false) && ! ($e['offensiveDefensive']['offensive'] ?? false));
        $ids = $entries->reject($situational)->pluck('spellId')->all();

        return $this->pressable[$externalSpecId] = Spell::whereIn('id', $ids)
            ->where('is_passive', false)
            ->where(fn ($q) => $q->whereNull('is_interrupt')->orWhere('is_interrupt', false))
            ->where(fn ($q) => $q->whereNull('is_mobility')->orWhere('is_mobility', false))
            ->whereNotIn('spell_id', WowAbilityFacts::UNIVERSAL_SPELL_IDS)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Spell $s) => [$s->display_name => true])
            ->all();
    }

    private function short(float $n): string
    {
        return $n >= 1e6 ? round($n / 1e6, 2).'M' : round($n / 1e3).'k';
    }
}
