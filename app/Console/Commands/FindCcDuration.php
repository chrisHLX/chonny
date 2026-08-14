<?php

namespace App\Console\Commands;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Scans every raw arena log already on file (data/arena-logs/raw/*.log.gz) for a given
 * spell's SPELL_AURA_APPLIED/REFRESH/REMOVED sequence and reports the observed duration of
 * every real instance — generalizes the manual timestamp-diffing done by hand for Cheap Shot
 * (2026-08-14, see "PVP duration.txt" in that same folder) and Grapple Weapon into a reusable
 * tool, directly answering "can the games find PvP CC durations for missing spells": yes,
 * confirmed on Grapple Weapon the same day this was built — 3 independent casts in one match
 * all measured 5.00-5.04s, matching the ability's own tooltip text exactly.
 *
 * Methodology, same as every manual case worked through before this existed: an aura's TRUE
 * duration can only be underestimated by an interrupted instance (trinket, dispel, death),
 * never overestimated — so the MAXIMUM observed duration across enough real instances is the
 * best available estimate of the true value, not an average (averaging would be dragged down
 * by every clipped instance, which are the majority in practice — see Cheap Shot's 11 real
 * casts, only 1 of which ran the full duration).
 *
 * A REFRESH mid-window (the target got re-CC'd while still under the effect) resets the
 * measurement window to the refresh's own timestamp, not the original APPLIED — the reported
 * duration for that instance is REMOVED_time - last_(APPLIED_or_REFRESH)_time, matching how a
 * real refresh actually behaves in-game (remaining duration resets to full, not additive).
 *
 * This does NOT determine cooldown — only aura duration. Two casts of the same ability by the
 * same source in one match give a real minimum cooldown FLOOR (the ability was clearly ready
 * again by that point), never an exact confirmed value — printed separately, clearly labeled
 * as a floor, never presented as the real cooldown.
 *
 * Usage: php artisan wow:find-cc-duration 233759
 */
class FindCcDuration extends Command
{
    protected $signature = 'wow:find-cc-duration {spellId : Blizzard external spell_id}';

    protected $description = 'Scan every locally-stored arena log for a spell\'s real aura-duration instances (max observed = best estimate of true duration)';

    public function handle(): int
    {
        $spellId = (int) $this->argument('spellId');

        $patch = Patch::where('is_current', true)->first();
        $spell = $patch ? Spell::where('patch_id', $patch->id)->where('spell_id', $spellId)->first() : null;

        if ($spell) {
            $this->info("Spell: {$spell->name} (id={$spellId}) — stored duration_seconds={$spell->duration_seconds}, pvp_duration_seconds={$spell->pvp_duration_seconds}");
        } else {
            $this->warn("Spell id={$spellId} not found in the current patch's spells table — searching logs anyway.");
        }

        $files = glob(base_path('data/arena-logs/raw/*.log.gz'));

        if ($files === []) {
            $this->error('No arena logs on file — run wow:fetch-arena-log first.');

            return self::FAILURE;
        }

        $this->info('Scanning '.count($files)." stored match log(s) for spell_id {$spellId}...\n");

        $allDurations = [];
        $castGapsPerMatch = [];
        $matchesWithHits = 0;

        foreach ($files as $file) {
            $matchId = basename($file, '.log.gz');
            $raw = gzdecode(File::get($file));

            if (!str_contains($raw, ",{$spellId},\"")) {
                continue;
            }

            $pattern = '/^([\d\/: .-]+)\s+(SPELL_AURA_APPLIED|SPELL_AURA_REFRESH|SPELL_AURA_REMOVED|SPELL_CAST_SUCCESS),([^,]+),"[^"]*",[^,]*,[^,]*,([^,]+),"([^"]*)",[^,]*,[^,]*,'.$spellId.',"[^"]*"/m';
            preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

            if ($matches === []) {
                continue;
            }

            $matchesWithHits++;
            $windows = []; // key: "source|dest" => ['start' => float|null]
            $castTimesBySource = [];
            $instanceNum = 0;

            foreach ($matches as $m) {
                $ts = $this->parseTimestamp($m[1]);
                $event = $m[2];
                $source = $m[3];
                $dest = $m[4];
                $key = "{$source}|{$dest}";

                if ($event === 'SPELL_CAST_SUCCESS') {
                    $castTimesBySource[$source][] = $ts;

                    continue;
                }

                if ($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REFRESH') {
                    $windows[$key] = $ts;

                    continue;
                }

                if ($event === 'SPELL_AURA_REMOVED' && isset($windows[$key])) {
                    $duration = round($ts - $windows[$key], 3);
                    $instanceNum++;
                    $allDurations[] = $duration;
                    $this->line("  [{$matchId}] instance {$instanceNum}: {$duration}s");
                    unset($windows[$key]);
                }
            }

            foreach ($castTimesBySource as $source => $times) {
                sort($times);
                for ($i = 1; $i < count($times); $i++) {
                    $castGapsPerMatch[] = round($times[$i] - $times[$i - 1], 3);
                }
            }
        }

        if ($matchesWithHits === 0) {
            $this->warn('No matches on file contain this spell_id at all.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($allDurations !== []) {
            $max = max($allDurations);
            $this->info(count($allDurations)." real duration instance(s) found across {$matchesWithHits} match(es).");
            $this->info("MAX observed (best estimate of true duration, per this project's clipped-instance-only-underestimates logic): {$max}s");
        } else {
            $this->warn('Spell was cast but no AURA_APPLIED->REMOVED window was captured (may not be an aura-granting effect, or always got dispelled/never landed).');
        }

        if ($castGapsPerMatch !== []) {
            sort($castGapsPerMatch);
            $this->newLine();
            $this->info('Observed gaps between successive casts by the same source (a MINIMUM cooldown floor only — never the confirmed exact value):');
            $this->line('  '.implode('s, ', array_map(fn ($g) => (string) $g, $castGapsPerMatch)).'s');
        }

        return self::SUCCESS;
    }

    private function parseTimestamp(string $raw): float
    {
        // "8/11/2026 19:35:07.6661" — date portion is irrelevant for a same-match diff, only
        // time-of-day + fractional seconds matter, so this deliberately ignores date rollover
        // (no match in this project's data runs past midnight).
        if (!preg_match('/(\d{1,2}):(\d{2}):(\d{2})\.(\d+)/', trim($raw), $m)) {
            return 0.0;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3] + ((float) ('0.'.$m[4]));
    }
}
