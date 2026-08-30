<?php

namespace App\Console\Commands;

use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Spell;
use App\Models\Specialization;
use App\Models\TalentNode;
use App\Models\TalentTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Re-resolves the `nodeId`/`entryId`/`pvpTalentId` foreign-key pointers EnrichRotationTalents
 * embeds into a promoted rotation file's `talentBuild`, against the CURRENT `talent_nodes`/
 * `talent_node_entries`/`pvp_talents` primary keys — without touching the raw archive at all.
 *
 * Why this exists, separately from just re-running EnrichRotationTalents: found 2026-08-30 that
 * 12 of 38 promoted rotation files (Rogue/Shaman/Warlock/Warrior, all specs) had a talentBuild
 * whose nodeId/entryId values were 100% stale — 0/75 resolved to any real row — while the same
 * files' pvpTalentId values still resolved fine. Traced via row timestamps, not guessed: the
 * affected nodes were all created 2026-08-03 and last actually modified 2026-08-28, while the
 * rotation files' own `resolvedAt` timestamp was 2026-08-27 — meaning the underlying
 * talent_nodes/talent_node_entries primary keys were rebuilt (almost certainly a migrate:fresh)
 * the day after these 12 files were enriched, and whatever re-enrichment pass ran afterward
 * appears to have stopped partway through an alphabetical sweep (Rogue onward never got
 * re-resolved). EnrichRotationTalents itself can't fix this on a machine that doesn't have the
 * raw archive on disk (extractCombatantInfo() reads metadata/{matchId}.json + raw/{matchId}.log.gz
 * from wow-arena-archive, confirmed absent locally for exactly these 12 files' matches) — but it
 * doesn't need to be re-decoded from scratch: each talent's real `spellId` (Blizzard's own,
 * patch-stable external id) and `rank` are already sitting in the file, untouched by any of this.
 * Re-deriving nodeId/entryId from those two fields against the live DB is a pure, safe FK
 * re-projection, not a new resolution — same posture as every other "the fact is already known,
 * only the pointer to it went stale" repair in this project (TalentBuild.spellbook_snapshot_id
 * relinking, etc.).
 *
 * Scoped the same way resolveCombatantTalents() scopes its own node lookup (class + this spec's
 * own spec tree + this spec's hero trees) — a spell_id could theoretically appear in more than
 * one of those trees' entries; ambiguous matches are flagged and left untouched rather than
 * guessed, same "flag, don't guess" rule as every other repair pass in this codebase.
 *
 * Usage:
 *   php artisan wow:repair-rotation-talent-ids                  # every promoted rotation file
 *   php artisan wow:repair-rotation-talent-ids rogue subtlety    # one spec only
 *   php artisan wow:repair-rotation-talent-ids --dry-run
 */
class RepairRotationTalentIds extends Command
{
    protected $signature = 'wow:repair-rotation-talent-ids {classSlug?} {specSlug?} {--dry-run : Report what would change without writing}';

    protected $description = "Re-resolve a promoted rotation file's stale talentBuild nodeId/entryId/pvpTalentId FKs against the current DB, using the already-stored spellId/rank — no raw archive access needed";

    public function handle(): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');
        $dryRun = (bool) $this->option('dry-run');
        $patchId = Patch::where('is_current', true)->value('id');

        $query = Specialization::with('gameClass')->orderBy('name');
        if ($classSlug) {
            $query->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug));
        }
        if ($specSlug) {
            $query->where('slug', $specSlug);
        }

        $repairedFiles = 0;
        $cleanFiles = 0;
        $unresolvable = 0;

        foreach ($query->get() as $spec) {
            $class = $spec->gameClass;
            $path = base_path("data/arena-logs/rotations/{$class->slug}/{$spec->slug}.json");

            if (!File::exists($path)) {
                continue;
            }

            $data = json_decode(File::get($path), true);
            if (!is_array($data) || !isset($data['topDpsWindowsByLength'])) {
                continue;
            }

            $entriesByKey = $this->specEntryLookup($spec, $patchId);
            $pvpBySpellId = PvpTalent::where('spec_id', $spec->id)->where('patch_id', $patchId)
                ->with('spell')->get()->keyBy(fn ($p) => $p->spell?->spell_id);

            $fileTouched = false;
            $fileHadStale = false;

            foreach ($data['topDpsWindowsByLength'] as $length => $window) {
                if (empty($window['talentBuild']['talents'])) {
                    continue;
                }

                $newTalents = [];
                $windowTouched = false;

                foreach ($window['talentBuild']['talents'] as $t) {
                    // spell_id+rank alone isn't always unique — confirmed real 2026-08-30: a
                    // hero tree can echo the exact same spell_id/rank as a spec talent (Warlock's
                    // "Seeds of Their Demise" exists as both an Affliction spec node and a
                    // Hellcaller hero node). The stale entry's own `treeType` field (already
                    // captured at original resolution time, untouched by any of this) is real,
                    // already-known data that disambiguates it — not a guess.
                    $key = "{$t['spellId']}|{$t['rank']}|{$t['treeType']}";
                    $match = $entriesByKey->get($key);

                    if ($match === null) {
                        // Ambiguous or unresolvable — leave exactly as-is, flag it, never guess.
                        $newTalents[] = $t;
                        if (!TalentNode::whereHas('entries.spell', fn ($q) => $q->where('spell_id', $t['spellId']))->whereKey($t['nodeId'] ?? 0)->exists()) {
                            $fileHadStale = true;
                        }

                        continue;
                    }

                    if ($match === 'ambiguous') {
                        $this->warn("  {$class->name}/{$spec->name} [{$length}s]: '{$t['name']}' (spell {$t['spellId']}, rank {$t['rank']}) matched more than one entry — left as-is.");
                        $newTalents[] = $t;

                        continue;
                    }

                    if ((int) ($t['nodeId'] ?? 0) !== $match['nodeId'] || (int) ($t['entryId'] ?? 0) !== $match['entryId']) {
                        $windowTouched = true;
                        $fileHadStale = true;
                    }

                    $t['nodeId'] = $match['nodeId'];
                    $t['entryId'] = $match['entryId'];
                    $newTalents[] = $t;
                }

                $newPvp = [];
                foreach ($window['talentBuild']['pvpTalents'] ?? [] as $p) {
                    $pvp = $pvpBySpellId->get($p['spellId'] ?? null);
                    if ($pvp && (int) ($p['pvpTalentId'] ?? 0) !== $pvp->id) {
                        $windowTouched = true;
                        $fileHadStale = true;
                    }
                    if ($pvp) {
                        $p['pvpTalentId'] = $pvp->id;
                    }
                    $newPvp[] = $p;
                }

                if ($windowTouched) {
                    $data['topDpsWindowsByLength'][$length]['talentBuild']['talents'] = $newTalents;
                    $data['topDpsWindowsByLength'][$length]['talentBuild']['pvpTalents'] = $newPvp;
                    $data['topDpsWindowsByLength'][$length]['talentBuild']['resolvedAt'] = now()->toAtomString();
                    $fileTouched = true;
                }
            }

            if (isset($data['topDpsWindowsByLength'][12])) {
                $data['topDpsWindow'] = $data['topDpsWindowsByLength'][12];
            }

            if ($fileTouched) {
                $repairedFiles++;
                $this->info("  {$class->name}/{$spec->name}: repaired.");
                if (!$dryRun) {
                    File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            } elseif ($fileHadStale) {
                $unresolvable++;
                $this->warn("  {$class->name}/{$spec->name}: had stale ids but none were resolvable — left untouched.");
            } else {
                $cleanFiles++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Repaired {$repairedFiles}, already clean {$cleanFiles}, unresolvable {$unresolvable}.");

        return self::SUCCESS;
    }

    /**
     * "{spell_id}|{rank}" => ['nodeId' => int, 'entryId' => int] for every entry in this spec's
     * class + spec + hero trees, or 'ambiguous' when more than one entry shares the same key —
     * same tree scoping ArenaLogService::resolveCombatantTalents() already uses.
     *
     * @return \Illuminate\Support\Collection<string, array{nodeId:int,entryId:int}|string>
     */
    private function specEntryLookup(Specialization $spec, ?int $patchId): \Illuminate\Support\Collection
    {
        $treeIds = TalentTree::where('patch_id', $patchId)
            ->where(function ($q) use ($spec) {
                $q->where(fn ($q2) => $q2->where('class_id', $spec->class_id)->where('type', 'class'))
                    ->orWhere(fn ($q2) => $q2->where('spec_id', $spec->id)->where('type', 'spec'))
                    ->orWhere(fn ($q2) => $q2->where('type', 'hero')
                        ->whereHas('specializations', fn ($q3) => $q3->where('specializations.id', $spec->id)));
            })
            ->get(['id', 'type'])
            ->keyBy('id');

        $nodes = TalentNode::whereIn('talent_tree_id', $treeIds->keys())->with('entries.spell')->get();

        $lookup = collect();
        foreach ($nodes as $node) {
            $treeType = $treeIds->get($node->talent_tree_id)?->type;

            foreach ($node->entries as $entry) {
                if (!$entry->spell) {
                    continue;
                }

                // Matches the composite key built from a rotation file's own stored fields
                // (spellId/rank/treeType) in handle() above — see the comment there for why
                // spell_id+rank alone isn't always unique.
                $key = "{$entry->spell->spell_id}|{$entry->rank}|{$treeType}";
                $value = ['nodeId' => $node->id, 'entryId' => $entry->id];

                $lookup[$key] = $lookup->has($key) ? 'ambiguous' : $value;
            }
        }

        return $lookup;
    }
}
