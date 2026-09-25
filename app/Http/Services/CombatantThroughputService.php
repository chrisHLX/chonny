<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\File;

/**
 * What each player in one archived match actually put out: effective healing, absorbs, overheal,
 * damage done and damage taken.
 *
 * WHY THIS EXISTS. Nothing in this project measured throughput before (confirmed by grep,
 * 2026-09-25). `PlayerMatchAnalysisService` answers "did they press this talent", `FindCcChains`
 * answers "what landed on whom" — but "did my healing actually beat the other healer's" had no
 * code behind it at all, which is the first question a player asks about their own game.
 *
 * EVERY FIELD OFFSET HERE WAS MEASURED, NOT ASSUMED, against a real 12.1.0.69933 log
 * (2026-09-25). They are read from the END of the line, because the advanced-combat-logging
 * block in the middle is long, undocumented in places and has grown between patches, while the
 * suffix is stable. The field counts were checked across a whole lobby and are fixed per event:
 *
 *   SPELL_HEAL / SPELL_PERIODIC_HEAL   36 fields   amount -5, overhealing -3
 *   SPELL_DAMAGE / ..._PERIODIC_DAMAGE 42 fields   amount -11  (trailing tag reads ST or AOE)
 *   SWING_DAMAGE / ..._LANDED          38 fields   amount -10
 *   SPELL_ABSORBED                     22 or 19    shield caster -10, absorbed -3
 *
 * SPELL_ABSORBED legitimately has two lengths — 22 when a spell was absorbed, 19 when a melee
 * swing was, because the swing form carries no spellId/name/school for the incoming hit. Reading
 * from the end is what makes one code path handle both; a fixed index would have silently read
 * the wrong field on 1,656 of 20,440 events in the sample.
 *
 * **SWING_DAMAGE AND SWING_DAMAGE_LANDED OVERLAP AND MUST BE DE-DUPLICATED.** Measured on one
 * lobby: 1,540 distinct SWING_DAMAGE events, 3,864 SWING_DAMAGE_LANDED, and 836 of them are the
 * same hit reported twice. Summing both over-counts melee by those 836; taking only
 * SWING_DAMAGE loses the 3,028 that appear as LANDED alone. So both are read and collapsed on
 * (timestamp, source, target, amount). Two genuinely simultaneous identical hits from one source
 * to one target would collapse into one, which is a rounding error against getting melee wrong
 * by either 54% or 66%.
 *
 * PETS are credited to their owner via SPELL_SUMMON, whose source is the summoner. A pet that was
 * already out before logging started has no summon line, so its damage cannot be attributed —
 * that total is reported separately as `unattributedPetDamage` rather than dropped or guessed
 * onto somebody. The advanced-param `ownerGUID` is NOT a way round this: on a pet's own damage
 * event it reads 0000000000000000, because those params describe the event's target, not its
 * source (checked on a real pet hit, 2026-09-25).
 *
 * A line whose amount fields do not parse as integers is counted in `unparsed` rather than
 * treated as zero, so a future client change shows up as a number instead of a quiet undercount.
 *
 * Read-only: reads the archive, writes nothing.
 */
class CombatantThroughputService
{
    /** Heals: 36 fields, amount 5th from the end, overhealing 3rd. */
    private const HEAL_EVENTS = ['SPELL_HEAL', 'SPELL_PERIODIC_HEAL'];

    /** Spell damage: 42 fields, amount 11th from the end. */
    private const SPELL_DAMAGE_EVENTS = ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE'];

    /** Melee: 38 fields, amount 10th from the end. Deduplicated — see the class docblock. */
    private const SWING_DAMAGE_EVENTS = ['SWING_DAMAGE', 'SWING_DAMAGE_LANDED'];

    public function __construct(private ArenaLogService $arena) {}

    /**
     * Totals for one match already in the archive, or null when its raw log is not on file.
     */
    public function forMatch(string $matchId): ?array
    {
        $rawPath = $this->arena->rawLogPath($matchId);

        if (! File::exists($rawPath)) {
            return null;
        }

        $raw = gzdecode(File::get($rawPath));

        if ($raw === false) {
            return null;
        }

        return $this->measure(explode("\n", $raw));
    }

    /**
     * @param  iterable<int, string>  $lines  raw log lines, timestamp included
     * @return array{players: array<string, array<string, int>>, unattributedPetDamage: int, unparsed: int}
     */
    public function measure(iterable $lines): array
    {
        $totals = [];
        $petOwner = [];
        $pendingPetDamage = [];
        $seenSwings = [];
        $unattributed = 0;
        $unparsed = 0;

        $bump = function (string $guid, string $key, int $by) use (&$totals) {
            $totals[$guid] ??= [
                'healingEffective' => 0, 'healingOverheal' => 0, 'absorbDone' => 0,
                'damageDone' => 0, 'damageTaken' => 0, 'deaths' => 0, 'feigns' => 0,
            ];
            $totals[$guid][$key] += $by;
        };

        foreach ($lines as $line) {
            if (! str_contains($line, '  ')) {
                continue;
            }

            [$ts, $body] = explode('  ', $line, 2);
            $body = rtrim($body);
            $event = strtok($body, ',');

            if ($event === false) {
                continue;
            }

            // Cheap gate: the vast majority of a combat log is none of these.
            $interesting = in_array($event, self::HEAL_EVENTS, true)
                || in_array($event, self::SPELL_DAMAGE_EVENTS, true)
                || in_array($event, self::SWING_DAMAGE_EVENTS, true)
                || $event === 'SPELL_ABSORBED'
                || $event === 'SPELL_SUMMON'
                || $event === 'UNIT_DIED';

            if (! $interesting) {
                continue;
            }

            $f = str_getcsv($body);
            $src = $f[1] ?? '';
            $dst = $f[5] ?? '';

            if ($event === 'SPELL_SUMMON') {
                if ($src !== '' && $dst !== '') {
                    $petOwner[$dst] = $src;

                    // A pet can deal damage before its summon line is reached only if the log
                    // was mid-fight; credit anything already parked under it now.
                    if (isset($pendingPetDamage[$dst])) {
                        $bump($src, 'damageDone', $pendingPetDamage[$dst]);
                        $unattributed -= $pendingPetDamage[$dst];
                        unset($pendingPetDamage[$dst]);
                    }
                }

                continue;
            }

            if ($event === 'UNIT_DIED') {
                if (str_starts_with($dst, 'Player-')) {
                    // The trailing field is `unconsciousOnDeath`: 1 is a feign, 0 a real death.
                    $bump($dst, count($f) > 9 && (string) end($f) !== '0' ? 'feigns' : 'deaths', 1);
                }

                continue;
            }

            if (in_array($event, self::HEAL_EVENTS, true)) {
                $amount = $f[count($f) - 5] ?? null;
                $over = $f[count($f) - 3] ?? null;

                if (! $this->isInt($amount) || ! $this->isInt($over)) {
                    $unparsed++;

                    continue;
                }

                $target = $this->creditTo($src, $petOwner);
                $bump($target, 'healingEffective', (int) $amount - (int) $over);
                $bump($target, 'healingOverheal', (int) $over);

                continue;
            }

            if ($event === 'SPELL_ABSORBED') {
                $caster = $f[count($f) - 10] ?? '';
                $absorbed = $f[count($f) - 3] ?? null;

                if (! $this->isInt($absorbed)) {
                    $unparsed++;

                    continue;
                }

                if ($caster !== '') {
                    $bump($this->creditTo($caster, $petOwner), 'absorbDone', (int) $absorbed);
                }

                continue;
            }

            // Damage.
            $isSwing = in_array($event, self::SWING_DAMAGE_EVENTS, true);
            $amount = $f[count($f) - ($isSwing ? 10 : 11)] ?? null;

            if (! $this->isInt($amount)) {
                $unparsed++;

                continue;
            }

            if ($isSwing) {
                // One melee hit is reported as SWING_DAMAGE, SWING_DAMAGE_LANDED or both.
                $key = $ts.'|'.$src.'|'.$dst.'|'.$amount;

                if (isset($seenSwings[$key])) {
                    continue;
                }

                $seenSwings[$key] = true;
            }

            $amount = (int) $amount;

            if (str_starts_with($dst, 'Player-')) {
                $bump($dst, 'damageTaken', $amount);
            }

            if (str_starts_with($src, 'Player-')) {
                $bump($src, 'damageDone', $amount);
            } elseif (isset($petOwner[$src])) {
                $bump($petOwner[$src], 'damageDone', $amount);
            } elseif ($src !== '' && $src !== '0000000000000000') {
                $pendingPetDamage[$src] = ($pendingPetDamage[$src] ?? 0) + $amount;
                $unattributed += $amount;
            }
        }

        return [
            'players' => $totals,
            'unattributedPetDamage' => max(0, $unattributed),
            'unparsed' => $unparsed,
        ];
    }

    /** A pet's output belongs to whoever summoned it, when the log said so. */
    private function creditTo(string $guid, array $petOwner): string
    {
        return $petOwner[$guid] ?? $guid;
    }

    private function isInt(mixed $v): bool
    {
        return is_string($v) && $v !== '' && ctype_digit(ltrim($v, '-'));
    }
}
