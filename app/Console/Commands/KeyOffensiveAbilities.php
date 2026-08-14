<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Surfaces a spec's "key offensive abilities" from real arena match data.
 *
 * Two sections, both drawn from the same real-cast data, deliberately NOT split by a
 * "is this a damage buff" classifier — that was tried 2026-08-14 (checking spell_effects for
 * a `Modify Damage Done` type) and confirmed unreliable the same day: Shadow Blades, a real
 * damage-amplifying cooldown, doesn't carry that exact effect type and fell through to the
 * wrong bucket. Per direct user instruction, replaced with a much simpler, more robust split
 * that needs no classification at all:
 *   - Direct damage dealt — ranked by total damage observed (unchanged from the first pass).
 *   - Offensive cooldowns — every Offensive-categorized ability actually cast that has a real
 *     cooldown_seconds, ranked by cooldown length. This captures Shadow Blades/Tiger's
 *     Fury/Pillar of Frost-shaped abilities for free (they have real cooldowns, whether or not
 *     they deal direct damage) without needing to know anything about *why* each one matters —
 *     just that it's a real, spec-defining offensive commitment on a cooldown. Deliberate
 *     overlap with the damage list is expected and fine (Mortal Strike, Colossus Smash etc.
 *     appear in both) — the two are different lenses on the same kit, not a partition.
 *
 * One ability can be split across several spell_id sub-effect records in the raw log
 * (Eviscerate showed as 4 separate rows on the first pass, Shadowstrike as 2) — closed via
 * ArenaLogService::mergeByCanonicalName(), which sums casts/damage across same-named siblings
 * and picks one canonical spell_id for display (cooldown/etc.) via the same CD/relationship
 * disambiguation heuristic already used to resolve this project's duplicate-name
 * baseline-spec-overrides.txt conflicts.
 *
 * Command-line only, no database writes, no UI — validates the approach before deciding how it
 * surfaces in the product.
 *
 * Usage: php artisan wow:key-offensive-abilities rogue subtlety
 */
class KeyOffensiveAbilities extends Command
{
    protected $signature = 'wow:key-offensive-abilities {classSlug} {specSlug}';

    protected $description = "Rank a spec's key offensive abilities (direct damage + offensive cooldowns) from real arena match data";

    public function handle(ModuleSpellReferenceService $spellService, ArenaLogService $arenaLogService): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        $class = GameClass::where('slug', $classSlug)->first();
        if (!$class) {
            $this->error("No class found for slug '{$classSlug}'.");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();
        if (!$spec) {
            $this->error("No spec found for slug '{$specSlug}' under {$class->name}.");

            return self::FAILURE;
        }

        $patch = Patch::where('is_current', true)->first();

        $playerGuidsByMatch = [];
        foreach (glob(base_path('data/arena-logs/metadata/*.json')) as $metaFile) {
            $meta = json_decode(File::get($metaFile), true);
            $matchId = basename($metaFile, '.json');

            foreach ($meta['units'] ?? [] as $u) {
                if (str_starts_with($u['id'], 'Player-') && (int) $u['spec'] === $spec->external_spec_id) {
                    $playerGuidsByMatch[$matchId][] = $u['id'];
                }
            }
        }

        if ($playerGuidsByMatch === []) {
            $this->warn("No matches on file contain a real {$class->name}/{$spec->name} player.");

            return self::SUCCESS;
        }

        $this->info("Scanning ".count($playerGuidsByMatch)." match(es) with a real {$class->name}/{$spec->name} player...");

        $castCounts = [];
        $damageTotals = [];

        foreach ($playerGuidsByMatch as $matchId => $guids) {
            $raw = gzdecode(File::get(base_path("data/arena-logs/raw/{$matchId}.log.gz")));

            foreach (array_unique($guids) as $guid) {
                $g = preg_quote($guid, '/');

                preg_match_all('/^[\d\/: .-]+\s+SPELL_CAST_SUCCESS,'.$g.',"[^"]*",[^,]*,[^,]*,[^,]*,"[^"]*",[^,]*,[^,]*,(\d+),"[^"]*"/m', $raw, $m);
                foreach ($m[1] as $spellId) {
                    $castCounts[(int) $spellId] = ($castCounts[(int) $spellId] ?? 0) + 1;
                }

                preg_match_all('/^[\d\/: .-]+\s+SPELL_(?:PERIODIC_)?DAMAGE,'.$g.',"[^"]*",[^,]*,[^,]*,[^,]*,"[^"]*",[^,]*,[^,]*,(\d+),"[^"]*",[^,]*,(?:[^,]*,){19}(\d+)/m', $raw, $m);
                foreach ($m[1] as $i => $spellId) {
                    $spellId = (int) $spellId;
                    $damageTotals[$spellId] = ($damageTotals[$spellId] ?? 0) + (int) $m[2][$i];
                }
            }
        }

        $allSpellIds = array_unique(array_merge(array_keys($castCounts), array_keys($damageTotals)));
        $rowsBySpellId = [];

        foreach ($allSpellIds as $spellId) {
            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', $spellId)->first();
            if (!$spell || $spell->not_in_spellbook) {
                continue;
            }

            if ($spellService->categorize($spell) !== 'Offensive') {
                continue;
            }

            $rowsBySpellId[$spellId] = [
                'spell' => $spell,
                'casts' => $castCounts[$spellId] ?? 0,
                'damage' => $damageTotals[$spellId] ?? 0,
            ];
        }

        $merged = $arenaLogService->mergeByCanonicalName($rowsBySpellId);

        $directDamage = array_filter($merged, fn ($r) => $r['damage'] > 0);
        $cooldowns = array_filter($merged, fn ($r) => $r['spell']->cooldown_seconds !== null);

        usort($directDamage, fn ($a, $b) => $b['damage'] <=> $a['damage']);
        usort($cooldowns, fn ($a, $b) => $b['spell']->cooldown_seconds <=> $a['spell']->cooldown_seconds);

        $grandTotal = array_sum(array_column($directDamage, 'damage'));

        $this->newLine();
        $this->info("=== Direct damage dealt (ranked by total damage across ".count($playerGuidsByMatch)." match(es)) ===");
        foreach (array_slice($directDamage, 0, 12) as $r) {
            $pct = $grandTotal > 0 ? round($r['damage'] / $grandTotal * 100, 1) : 0;
            $cd = $r['spell']->cooldown_seconds ? "{$r['spell']->cooldown_seconds}s CD" : 'no CD';
            $this->line(sprintf('  %-28s %10s dmg (%4s%%)  %4d casts  %s', $r['name'], number_format($r['damage']), $pct, $r['casts'], $cd));
        }

        $this->newLine();
        $this->info('=== Offensive cooldowns (every Offensive ability actually cast with a real cooldown, ranked by CD length) ===');
        foreach ($cooldowns as $r) {
            $dmgNote = $r['damage'] > 0 ? ' — '.number_format($r['damage']).' dmg dealt' : ' — no direct damage (buff/utility CD)';
            $this->line(sprintf('  %-28s %6ss CD  %4d casts%s', $r['name'], $r['spell']->cooldown_seconds, $r['casts'], $dmgNote));
        }

        return self::SUCCESS;
    }
}
