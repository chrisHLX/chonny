<?php

namespace App\Console\Commands;

use App\Http\Services\SpellDataFileParser;
use App\Http\Services\TalentSelectionService;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellbookSnapshot;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;
use App\Models\SpellRelationship;
use App\Models\TalentNode;
use App\Models\TalentNodeEdge;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\TalentTreeSpecialization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

/**
 * Imports the flat-file game reference data (data/spelldata/filtered/{class}/*.txt,
 * data/talenttrees/{class}.json, data/pvptalents/{class}.json) into the relational schema,
 * tagged to a games/patches row for the given game+patch build.
 *
 * File layout is currently WoW-shaped (a "class" folder per playable class) — the flat-file
 * *reader* is game-specific by necessity of the source data, but the schema and models
 * underneath it are not; a future game with a different source shape would need its own
 * import command variant reusing the same tables, not a rewrite of this one.
 *
 * Safe to re-run: every row is written via upsertTrack(), keyed on each table's natural unique
 * constraint — re-running against unchanged source files produces zero writes.
 *
 * spell_relationships carries these relationship_type values: 'modifies' (effect-value modifiers,
 * from Affecting Spells/Modified By — importRelationships(); upgraded to 'modifies_cooldown' with
 * a real magnitude when the ref's own effect#N is a known cooldown-modifier shape — see
 * modifiesRelationshipMapping(), added 2026-08-05), 'replaces' (action-bar spell
 * replacement, from Talent Entry's replace= annotation — importReplacesRelationships()), and five
 * values from the "Category"/"Affected Spells (Category)" structural pair
 * (importCategoryRelationships(), split by categoryRelationshipMapping() as of 2026-08-01 — see
 * game-data.md's "modifies_charges is a coarser label" finding): 'modifies_charges' (charge-count
 * grants, "Modify Cooldown Charge (Category)" only — computed, +N charges), 'modifies_cooldown'
 * (cooldown-duration modifiers — shared with the PvP-talent-sourced case below; only the flat-
 * seconds "Modify Recharge Time (Category)" variant is computed, the percent/non-charge variants
 * are descriptive-only), 'modifies_charge_rate' (charge recharge-rate modifiers, descriptive-only),
 * 'hasted_cooldown' (haste-scaling tags, descriptive-only), and 'bypasses_cooldown' (conditional
 * cooldown-bypass tags, descriptive-only). 'modifies_cooldown' is also written by
 * importPvpTalentRelationships() — cooldown modifiers parsed from PvP talent free-text
 * descriptions, the one relationship type sourced from prose rather than a structured field, since
 * pvptalents JSON carries no spell_id references at all.
 *
 * spell_class_availability.spec_id for baseline.txt records is refined per-record from each
 * record's own "Class:" field (see resolveBaselineSpecIds()) rather than trusting the filename
 * alone — baseline.txt as a file mixes genuinely class-wide spells with spec-restricted ones
 * (e.g. Shadow Priest's Vampiric Embrace), and only the per-record field can tell them apart.
 *
 * data/spelldata/filtered/{class} folder names don't always match data/talenttrees|pvptalents/
 * {class}.json filenames exactly (e.g. "demonhunter" vs "demon-hunter.json") — matched via
 * normalizeSlug() rather than an exact filename join.
 */
class ImportSpellData extends Command
{
    protected $signature = 'import:spelldata
        {game : Game slug, e.g. wow}
        {patch : Patch build version, e.g. 12.0.7.68887}
        {--current : Mark this patch as the current one for the game}
        {--only= : Comma-separated class folder names to limit the import to, e.g. --only=priest}';

    protected $description = 'Imports flat-file spell/talent/pvp-talent data into the relational game-reference schema.';

    private const GAME_NAMES = [
        'wow' => 'World of Warcraft',
        'sc2' => 'StarCraft II',
    ];

    private const TRACKED_TABLES = [
        'games', 'patches', 'classes', 'specializations',
        'spells', 'spell_effects', 'spell_class_availability',
        'talent_trees', 'talent_nodes', 'talent_node_entries', 'talent_node_edges',
        'talent_tree_specializations',
        'pvp_talents', 'spell_relationships',
    ];

    private SpellDataFileParser $parser;

    /** @var array<string, array{created: int, updated: int, unchanged: int}> */
    private array $counts = [];

    /** @var array<int, Spell> external spell_id => Spell, accumulated across the whole run */
    private array $spellIndex = [];

    /** @var array<int, array> flattened parsed spell records, retained for the relationship pass */
    private array $pendingRelationshipRecords = [];

    private int $relationshipSkips = 0;

    private int $categoryRelationshipSkips = 0;

    private int $replacesRelationshipSkips = 0;

    /** @var array<int, array{spell_id: int, description: string, class_id: int}> pvp talent spells pending description-parse, retained for the relationship pass */
    private array $pendingPvpTalentRecords = [];

    private int $pvpTalentRelationshipSkips = 0;

    private int $pvpTalentRelationshipMatches = 0;

    private int $heroTreeSpecSkips = 0;

    /** @var array<int, int> external spell_id => the external spell_id its description points at */
    private array $pendingDescriptionRefs = [];

    private int $descriptionRefSkips = 0;

    private int $baselineOverrideSkips = 0;

    private int $baselineOverridesApplied = 0;

    private int $manualSpellsApplied = 0;

    private int $manualSpellsSkipped = 0;

    private int $ccSynergyOverridesApplied = 0;

    private int $ccSynergyOverrideSkips = 0;

    private int $scalarCorrectionsApplied = 0;

    private int $scalarCorrectionSkips = 0;

    public function handle(SpellDataFileParser $parser): int
    {
        $this->parser = $parser;

        foreach (self::TRACKED_TABLES as $table) {
            $this->counts[$table] = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $gameSlug = Str::slug($this->argument('game'));
        $patchVersion = (string) $this->argument('patch');
        $onlyOption = $this->option('only');
        $only = $onlyOption ? array_map($this->normalizeSlug(...), array_map('trim', explode(',', $onlyOption))) : null;

        $game = $this->upsertTrack(Game::class, ['slug' => $gameSlug], [
            'name' => self::GAME_NAMES[$gameSlug] ?? Str::headline($gameSlug),
        ], 'games');
        $this->info("Game: {$game->name} ({$game->slug})");

        $patch = $this->upsertTrack(Patch::class, [
            'game_id' => $game->id,
            'build_version' => $patchVersion,
        ], [], 'patches');

        if ($this->option('current')) {
            $patch->markCurrent();
        }
        $this->info("Patch: {$patch->build_version}".($patch->fresh()->is_current ? ' (current)' : ''));

        $classDataRoot = base_path('data/spelldata/filtered');
        if (!File::isDirectory($classDataRoot)) {
            $this->error("No spelldata directory found at {$classDataRoot}");

            return self::FAILURE;
        }

        $classDirs = collect(File::directories($classDataRoot))
            ->filter(fn (string $dir) => $only === null || in_array($this->normalizeSlug(basename($dir)), $only, true))
            ->values();

        if ($classDirs->isEmpty()) {
            $this->warn('No class directories matched — nothing to import.');

            return self::SUCCESS;
        }

        foreach ($classDirs as $classDir) {
            DB::transaction(fn () => $this->importClass($game, $patch, $classDir));
        }

        $this->importRelationships();
        $this->importCategoryRelationships();
        $this->importReplacesRelationships();
        $this->importPvpTalentRelationships();
        $this->resolveDescriptionReferences();
        $this->importManualSpells($patch);
        $this->importBaselineSpecOverrides($patch);
        $this->importCcSynergyOverrides($patch);
        $this->importScalarCorrections($patch);

        // Retroactive cleanup for stale class-tree talent nodes that duplicate a spec-tree
        // talent (see TalentSelectionService::cleanupClassTreeBloat()'s docblock and CLAUDE.md's
        // "cross-spec spell leakage" section) — runs BEFORE the same-position collision cleanup
        // below since removing a stale duplicate node can itself resolve what would otherwise
        // look like a same-position collision. Same "always run this defensive pass" precedent
        // as importBaselineSpecOverrides() above.
        $bloatReport = app(TalentSelectionService::class)->cleanupClassTreeBloat();
        if ($bloatReport !== []) {
            $this->warn('  Removed '.count($bloatReport).' stale class-tree talent node(s) (spell also present in a spec tree):');
            $byClass = collect($bloatReport)->groupBy('class');
            foreach ($byClass as $className => $rows) {
                $this->line("    [{$className}] {$rows->count()} stale node(s), e.g. ".$rows->pluck('spell')->unique()->take(3)->implode(', '));
            }
        }

        // Retroactive cleanup for same-position ACTIVE-node duplicates sharing an identical
        // talent name (see TalentSelectionService::cleanupDuplicateSpellNodes()'s docblock and
        // CLAUDE.md — found 2026-08-17 via "Intimidation"/Moonkin Form/Starsurge/Starfire each
        // showing 2-3 times). Runs BEFORE the same-position collision cleanup below, same
        // ordering rationale as the class-tree-bloat pass above — merging a genuine duplicate
        // node down to one can itself resolve what would otherwise look like a same-position
        // collision. Same "always run this defensive pass" precedent as the others.
        $duplicateNodeReport = app(TalentSelectionService::class)->cleanupDuplicateSpellNodes();
        if ($duplicateNodeReport !== []) {
            $this->warn('  Merged '.count($duplicateNodeReport).' duplicate talent node(s) sharing an identical name:');
            foreach ($duplicateNodeReport as $row) {
                $this->line("    [{$row['tree']}] dropped '{$row['dropped']}' (node {$row['dropped_node_id']}), kept node {$row['kept_node_id']}");
            }
        }

        // Retroactive cleanup for talent_build_choices rows written before the same-position
        // collision guard existed (see TalentSelectionService::cleanupSamePositionCollisions()'s
        // docblock and CLAUDE.md's "Same-position ACTIVE node collisions" section) — runs
        // unconditionally on every import, same "always run this defensive pass" precedent as
        // importBaselineSpecOverrides() above, so a plain re-import is enough to fix this on any
        // environment without a separate command to remember.
        $collisionReport = app(TalentSelectionService::class)->cleanupSamePositionCollisions();
        if ($collisionReport !== []) {
            $this->warn('  Removed '.count($collisionReport).' same-position talent collision row(s):');
            foreach ($collisionReport as $row) {
                $label = $row['is_default'] ? "default build #{$row['build_id']}" : "build #{$row['build_id']}";
                $this->line("    [{$label}] dropped '{$row['dropped_spell']}' (node {$row['dropped_node_id']}), kept '{$row['kept_spell']}' (node {$row['kept_node_id']})");
            }
        }

        // Spell data (cooldowns, descriptions, mechanic, effects) may have changed for any
        // spec touched by this run — bump the shared version counter WowComps/SpellExplorer's
        // Redis cache keys off, rather than trying to enumerate which specs are affected.
        app(TalentSelectionService::class)->bumpSpellCacheVersion();

        $this->printSummary();
        $this->runSpellbookDiffCheck();

        return self::SUCCESS;
    }

    private function importClass(Game $game, Patch $patch, string $classDir): void
    {
        $folderName = basename($classDir);
        $classSlug = Str::slug($folderName);

        $treeJson = $this->loadMatchingJson(base_path('data/talenttrees'), $folderName);
        $pvpJson = $this->loadMatchingJson(base_path('data/pvptalents'), $folderName);

        $className = $treeJson['class'] ?? $pvpJson['class'] ?? Str::headline($folderName);

        $this->info("Importing class: {$className} ({$classSlug})");

        $class = $this->upsertTrack(GameClass::class, [
            'game_id' => $game->id,
            'slug' => $classSlug,
        ], [
            'name' => $className,
        ], 'classes');

        // Specializations are imported before spells (reordered from spells-first) so
        // importClassSpells() can resolve a spec-named file (e.g. discipline.txt) to its
        // Specialization id for spell_class_availability — classifyFileSource() needs this map.
        $classSpecs = [];
        if ($treeJson !== null) {
            $classSpecs = $this->importSpecializations($class, $treeJson);
        } else {
            $this->warn("  No matching talent tree JSON for '{$folderName}' — skipping talent trees/specializations.");
        }

        $records = $this->importClassSpells($class, $patch, $classDir, $classSpecs);
        $this->pendingRelationshipRecords = array_merge($this->pendingRelationshipRecords, array_values($records));

        if ($treeJson !== null) {
            $heroTreeSpecs = $this->scanHeroTreeSpecs($classDir);
            $this->importTalentTrees($class, $patch, $classSpecs, $treeJson, $heroTreeSpecs);
        }

        if ($pvpJson !== null) {
            $this->importPvpTalents($class, $patch, $classSpecs, $pvpJson);
        } else {
            $this->warn("  No matching pvp talent JSON for '{$folderName}' — skipping pvp talents.");
        }
    }

    /**
     * Classifies a spelldata .txt filename into a spell_class_availability (source, spec_id)
     * pair. baseline.txt and class-talents.txt are class-wide (spec_id null) at the file level;
     * hero-*.txt trees aren't attributable to a single spec from this data (spec_id null, still
     * correctly class-attributed); anything else is matched against the class's spec names.
     *
     * baseline.txt's file-level null is only a starting point — resolveBaselineSpecIds() refines
     * it per-record afterward, since SimC's baseline dump isn't actually spec-filtered (see that
     * method's docblock).
     *
     * @param  array<string, Specialization>  $classSpecs
     * @return array{0: string, 1: ?int}
     */
    private function classifyFileSource(string $filename, array $classSpecs): array
    {
        $base = $this->normalizeSlug(pathinfo($filename, PATHINFO_FILENAME));

        if ($base === 'baseline') {
            return ['baseline', null];
        }

        if ($base === 'classtalents') {
            return ['talent', null];
        }

        if (str_starts_with($base, 'hero')) {
            return ['talent', null];
        }

        foreach ($classSpecs as $specName => $spec) {
            if ($this->normalizeSlug($specName) === $base) {
                return ['talent', $spec->id];
            }
        }

        return ['talent', null];
    }

    /**
     * Refines a baseline.txt record's spec availability using its own "Class:" field (e.g.
     * "Shadow Priest", "Discipline Priest, Holy Priest", "Paladin, Priest", or the generic
     * "Priest"). Returns one or more spec ids when the field names specific specs of the
     * current class; returns [null] (unchanged, class-wide) whenever the field is generic,
     * unparseable, or names only other classes (shared consumables/racials) — deliberately
     * conservative, since a false-narrow reading here would make a spell silently disappear
     * from a spec that should see it, which is worse than the over-broad status quo it replaces
     * for the cases it can't confidently resolve.
     *
     * @param  array<string, Specialization>  $classSpecs
     * @return array<int, ?int>
     */
    private function resolveBaselineSpecIds(?string $classField, string $className, array $classSpecs): array
    {
        if ($classField === null) {
            return [null];
        }

        $namedSpecIds = [];
        $sawGenericToken = false;

        foreach (explode(',', $classField) as $token) {
            $token = trim($token);

            if ($token === $className) {
                $sawGenericToken = true;

                continue;
            }

            if (preg_match('/^(.+)\s+'.preg_quote($className, '/').'$/', $token, $m) && isset($classSpecs[trim($m[1])])) {
                $namedSpecIds[] = $classSpecs[trim($m[1])]->id;
            }
        }

        if ($sawGenericToken || $namedSpecIds === []) {
            return [null];
        }

        return array_values(array_unique($namedSpecIds));
    }

    /**
     * Resolves a class-tree talent's free=(...) spec-name list (see SpellDataFileParser) to spec
     * ids — the parallel narrowing to resolveBaselineSpecIds() above, but for the tree=class case:
     * Blizzard sometimes restricts a shared class-tree talent to a subset of the class's specs
     * (e.g. Mind Blast: Discipline and Shadow, never Holy), found 2026-08-01 by cross-checking
     * the in-game Spellbook UI against this data — Mind Blast was importing as class-wide because
     * nothing read this annotation at all before this fix. Falls back to [null] (class-wide,
     * the exact previous behavior) if none of the named specs resolve — same "never guess
     * narrower than confidently supported" posture as resolveBaselineSpecIds().
     *
     * @param  array<int, string>  $freeSpecs
     * @param  array<string, Specialization>  $classSpecs
     * @return array<int, ?int>
     */
    private function resolveFreeSpecIds(array $freeSpecs, array $classSpecs): array
    {
        $specIds = [];

        foreach ($freeSpecs as $specName) {
            if (isset($classSpecs[$specName])) {
                $specIds[] = $classSpecs[$specName]->id;
            }
        }

        return $specIds === [] ? [null] : array_values(array_unique($specIds));
    }

    /**
     * @param  array<string, Specialization>  $classSpecs
     * @return array<int, array> parsed spell records keyed by external spell_id
     */
    private function importClassSpells(GameClass $class, Patch $patch, string $classDir, array $classSpecs): array
    {
        $records = [];

        /** @var array<int, array<string, array{source: string, spec_id: ?int}>> $availabilityBySpellId */
        $availabilityBySpellId = [];

        foreach (File::glob($classDir.'/*.txt') as $file) {
            [$source, $specId] = $this->classifyFileSource(basename($file), $classSpecs);

            foreach ($this->parser->parseFile($file) as $record) {
                $records[$record['spell_id']] = $record;

                // baseline.txt is class-wide at the *file* level (classifyFileSource() has no
                // finer signal to go on than the filename), but SimC's baseline dump isn't
                // actually spec-filtered — it includes spec-restricted baseline passives (e.g.
                // Vampiric Embrace) alongside genuinely universal ones. Each baseline record's
                // own "Class:" field can disambiguate; other sources (talent files, hero trees)
                // are already correctly attributed by classifyFileSource() and skip this.
                $specIds = match (true) {
                    $source === 'baseline' => $this->resolveBaselineSpecIds($record['class_field'], $class->name, $classSpecs),
                    !empty($record['free_specs']) => $this->resolveFreeSpecIds($record['free_specs'], $classSpecs),
                    default => [$specId],
                };

                foreach ($specIds as $resolvedSpecId) {
                    $key = $source.'|'.($resolvedSpecId ?? 'null');
                    $availabilityBySpellId[$record['spell_id']][$key] = ['source' => $source, 'spec_id' => $resolvedSpecId];
                }
            }
        }

        foreach ($records as $record) {
            $values = [
                'name' => $record['name'],
                'school' => $record['school'],
                'charges' => $record['charges'],
                'cooldown_seconds' => $record['cooldown_seconds'],
                'duration_seconds' => $record['duration_seconds'],
                'not_in_spellbook' => $record['not_in_spellbook'],
                'variables' => $record['variables'],
                'mechanic' => $record['mechanic'],
                'spell_type' => $record['spell_type'],
                'range_yards' => $record['range_yards'],
                'is_passive' => $record['is_passive'],
                'cast_type' => $record['cast_type'],
            ];

            // A pointer-form record (description_ref !== null) always parses to description=null
            // — re-parsing the same raw file never yields anything else, since the pointer chain
            // needs the full cross-class spell index that only exists after every class is
            // loaded (see resolveDescriptionReferences()). Omitting the key here (rather than
            // writing null) leaves an already-resolved value from a previous run untouched
            // instead of clobbering it back to null on every single re-run, only for
            // resolveDescriptionReferences() to write the correct value back a moment later —
            // a permanent, non-converging Updated/Updated flap that never reaches Unchanged.
            if ($record['description_ref'] === null) {
                $values['description'] = $record['description'];
            }

            $spell = $this->upsertTrack(Spell::class, [
                'patch_id' => $patch->id,
                'spell_id' => $record['spell_id'],
            ], $values, 'spells');

            $this->spellIndex[$record['spell_id']] = $spell;

            if ($record['description_ref'] !== null) {
                $this->pendingDescriptionRefs[$record['spell_id']] = $record['description_ref'];
            }

            foreach ($record['effects'] as $effect) {
                $this->upsertTrack(SpellEffect::class, [
                    'spell_id' => $spell->id,
                    'effect_index' => $effect['effect_index'],
                ], [
                    'type' => $effect['type'],
                    'base_value' => $effect['base_value'],
                    'scaled_value' => $effect['scaled_value'],
                    'sp_coefficient' => $effect['sp_coefficient'],
                    'pvp_coefficient' => $effect['pvp_coefficient'],
                    'rank_op' => $effect['rank_op'],
                    'rank_values' => $effect['rank_values'],
                ], 'spell_effects');
            }

            foreach ($availabilityBySpellId[$record['spell_id']] as $availability) {
                $this->upsertTrack(SpellClassAvailability::class, [
                    'spell_id' => $spell->id,
                    'class_id' => $class->id,
                    'spec_id' => $availability['spec_id'],
                    'source' => $availability['source'],
                ], [], 'spell_class_availability');
            }
        }

        return $records;
    }

    /**
     * @return array<string, Specialization> spec name => Specialization
     */
    private function importSpecializations(GameClass $class, array $treeJson): array
    {
        $specs = [];

        foreach ($treeJson['specs'] ?? [] as $specName => $specData) {
            $specs[$specName] = $this->upsertTrack(Specialization::class, [
                'class_id' => $class->id,
                'slug' => Str::slug($specName),
            ], [
                'name' => $specName,
                'external_spec_id' => $specData['spec_id'] ?? null,
            ], 'specializations');
        }

        return $specs;
    }

    /**
     * @param  array<string, Specialization>  $classSpecs
     * @param  array<string, array<int, string>>  $heroTreeSpecs  normalizeSlug(hero tree name) => spec names, from scanHeroTreeSpecs()
     */
    private function importTalentTrees(GameClass $class, Patch $patch, array $classSpecs, array $treeJson, array $heroTreeSpecs = []): void
    {
        $classTree = $this->upsertTrack(TalentTree::class, [
            'patch_id' => $patch->id,
            'class_id' => $class->id,
            'type' => 'class',
            'name' => $class->name.' Class',
        ], [
            'spec_id' => null,
            'external_tree_id' => $treeJson['talent_tree_id'] ?? null,
        ], 'talent_trees');
        $this->importTreeNodes($classTree, $patch->id, $treeJson['class_talents']['nodes'] ?? []);

        foreach ($treeJson['specs'] ?? [] as $specName => $specData) {
            $tree = $this->upsertTrack(TalentTree::class, [
                'patch_id' => $patch->id,
                'class_id' => $class->id,
                'type' => 'spec',
                'name' => $specName,
            ], [
                'spec_id' => ($classSpecs[$specName] ?? null)?->id,
                'external_tree_id' => $specData['spec_id'] ?? null,
            ], 'talent_trees');
            $this->importTreeNodes($tree, $patch->id, $specData['nodes'] ?? []);
        }

        foreach ($treeJson['hero_talent_trees'] ?? [] as $heroData) {
            $heroName = $heroData['name'] ?? ('Hero Tree #'.($heroData['id'] ?? '?'));

            $tree = $this->upsertTrack(TalentTree::class, [
                'patch_id' => $patch->id,
                'class_id' => $class->id,
                'type' => 'hero',
                'name' => $heroName,
            ], [
                'spec_id' => null,
                'external_tree_id' => $heroData['id'] ?? null,
            ], 'talent_trees');
            $this->importTreeNodes($tree, $patch->id, $heroData['nodes'] ?? []);

            foreach ($heroTreeSpecs[$this->normalizeSlug($heroName)] ?? [] as $specName) {
                $spec = $classSpecs[$specName] ?? null;

                if (!$spec) {
                    $this->heroTreeSpecSkips++;

                    continue;
                }

                $this->upsertTrack(TalentTreeSpecialization::class, [
                    'talent_tree_id' => $tree->id,
                    'specialization_id' => $spec->id,
                ], [], 'talent_tree_specializations');
            }
        }
    }

    private function importTreeNodes(TalentTree $tree, int $patchId, array $nodesData): void
    {
        /** @var array<int, TalentNode> $nodesByExternalId */
        $nodesByExternalId = [];

        foreach ($nodesData as $nodeData) {
            $nodesByExternalId[$nodeData['id']] = $this->upsertTrack(TalentNode::class, [
                'talent_tree_id' => $tree->id,
                'external_node_id' => $nodeData['id'],
            ], [
                'type' => $nodeData['node_type'] ?? 'UNKNOWN',
                'pos_x' => $nodeData['raw_position_x'] ?? null,
                'pos_y' => $nodeData['raw_position_y'] ?? null,
                'max_ranks' => $nodeData['max_ranks'] ?? 1,
            ], 'talent_nodes');
        }

        foreach ($nodesData as $nodeData) {
            $node = $nodesByExternalId[$nodeData['id']];

            foreach ($nodeData['ranks'] ?? [] as $rankData) {
                $rankNumber = $rankData['rank'] ?? 1;
                $choices = $rankData['choices'] ?? [$rankData];

                foreach ($choices as $choice) {
                    if (!isset($choice['spell_id'])) {
                        continue;
                    }

                    $spell = $this->resolveOrCreateSpell(
                        $patchId,
                        $choice['spell_id'],
                        $choice['spell_name'] ?? $choice['talent_name'] ?? "Unknown Spell #{$choice['spell_id']}",
                        $choice['description'] ?? null,
                    );

                    $this->upsertTrack(TalentNodeEntry::class, [
                        'talent_node_id' => $node->id,
                        'rank' => $rankNumber,
                        'spell_id' => $spell->id,
                    ], [
                        'max_rank' => $nodeData['max_ranks'] ?? 1,
                        'external_talent_id' => $choice['talent_id'] ?? null,
                    ], 'talent_node_entries');
                }
            }

            // Only 'unlocks' is imported as the structural edge source — 'locked_by' is the
            // same relationship viewed from the other end and is redundant in well-formed data.
            foreach ($nodeData['unlocks'] ?? [] as $targetExternalId) {
                if (!isset($nodesByExternalId[$targetExternalId])) {
                    continue;
                }

                $this->upsertTrack(TalentNodeEdge::class, [
                    'from_node_id' => $node->id,
                    'to_node_id' => $nodesByExternalId[$targetExternalId]->id,
                    'edge_type' => 'unlocks',
                ], [], 'talent_node_edges');
            }
        }
    }

    /**
     * @param  array<string, Specialization>  $classSpecs
     */
    private function importPvpTalents(GameClass $class, Patch $patch, array $classSpecs, array $pvpJson): void
    {
        foreach ($pvpJson['specs'] ?? [] as $specName => $specData) {
            $spec = $classSpecs[$specName] ?? null;

            if (!$spec) {
                $this->warn("  Skipping pvp talents for spec '{$specName}' — no matching specialization imported.");

                continue;
            }

            foreach ($specData['pvp_talents'] ?? [] as $pvpTalent) {
                $spell = $this->resolveOrCreateSpell(
                    $patch->id,
                    $pvpTalent['spell_id'],
                    $pvpTalent['spell_name'] ?? $pvpTalent['name'],
                    $pvpTalent['description'] ?? null,
                );

                $this->upsertTrack(PvpTalent::class, [
                    'patch_id' => $patch->id,
                    'external_pvp_talent_id' => $pvpTalent['id'],
                ], [
                    'spec_id' => $spec->id,
                    'spell_id' => $spell->id,
                    'unlock_level' => $pvpTalent['unlock_player_level'] ?? 0,
                ], 'pvp_talents');

                $this->upsertTrack(SpellClassAvailability::class, [
                    'spell_id' => $spell->id,
                    'class_id' => $class->id,
                    'spec_id' => $spec->id,
                    'source' => 'pvp_talent',
                ], [], 'spell_class_availability');

                if (!empty($pvpTalent['description'])) {
                    $this->pendingPvpTalentRecords[] = [
                        'spell_id' => $pvpTalent['spell_id'],
                        'description' => $pvpTalent['description'],
                        'class_id' => $class->id,
                    ];
                }
            }
        }
    }

    /**
     * Populates spell_relationships from the structural "Affecting Spells" (spell-level) and
     * "Modified By" (effect-level) references captured by SpellDataFileParser — both list the
     * *other* spell that modifies the current record as an explicit id, not free text. Run once
     * at the end, after every class's spells have been imported, since a modifier can live in a
     * different class file than the spell it modifies (e.g. a shared/baseline entry).
     *
     * relationship_type/magnitude: each ref carries an optional effect_index (the "effect#N"
     * annotation SpellDataFileParser::parseSpellRefs() now retains) naming which specific effect
     * on the *source* spell produced the relationship — modifiesRelationshipMapping() looks that
     * effect up and converts known cooldown-modifier shapes to 'modifies_cooldown' with a real
     * magnitude, the same way importCategoryRelationships()'s categoryRelationshipMapping() does
     * for the Category-marker pair. Everything else stays a bare 'modifies' relationship with no
     * magnitude, same as before this addition (2026-08-05).
     */
    private function importRelationships(): void
    {
        foreach ($this->pendingRelationshipRecords as $record) {
            $target = $this->spellIndex[$record['spell_id']] ?? null;

            if (!$target) {
                continue;
            }

            $sources = $record['affecting_spells'];
            foreach ($record['effects'] as $effect) {
                $sources += $effect['modified_by'];
            }

            foreach ($sources as $sourceExternalId => $refInfo) {
                $source = $this->spellIndex[$sourceExternalId] ?? null;

                if (!$source || $source->id === $target->id) {
                    $this->relationshipSkips++;

                    continue;
                }

                // FIXED 2026-08-12 — a source can affect the same target via MORE than one of its
                // own effects at once (Blizzard's plural "effects: #1, #2" annotation, see
                // SpellDataFileParser::parseSpellRefs()'s docblock — confirmed real via Anti-Magic
                // Barrier's cooldown-reduction (#1) + duration-increase (#2) both targeting
                // Anti-Magic Shell). Looping over every index and writing one row per distinct
                // resolved type mirrors the same "don't dedupe by source alone" fix already
                // applied on the read side (ModuleSpellReferenceService::modifiersFor(), Bug 1,
                // 2026-08-02) — a source having multiple distinct relationship types to the same
                // target is a normal, expected pattern, not a duplicate to collapse. Falls back to
                // a single null-index pass when a ref has no parsed indices at all (unchanged
                // behavior from before this fix).
                $effectIndexes = !empty($refInfo['effect_indexes']) ? $refInfo['effect_indexes'] : [null];

                foreach ($effectIndexes as $effectIndex) {
                    $mapping = $this->modifiesRelationshipMapping($source, $effectIndex);

                    // modifier_value/unit are always written explicitly (even as null) — not just
                    // added when present. A prior import (before rank-awareness existed) may have
                    // already written a real magnitude for a pair that's now correctly recognized
                    // as rank-scaled and deferred; omitting these keys here would leave that
                    // stale, now-wrong number in place forever, since upsertTrack()'s fill() only
                    // touches keys actually present in $values.
                    $values = [
                        'effect_index' => $effectIndex,
                        'modifier_value' => $mapping['value'],
                        'modifier_unit' => $mapping['unit'],
                    ];

                    $this->upsertTrack(SpellRelationship::class, [
                        'source_spell_id' => $source->id,
                        'target_spell_id' => $target->id,
                        'relationship_type' => $mapping['type'],
                    ], $values, 'spell_relationships');
                }
            }
        }
    }

    /**
     * Resolves a plain 'modifies' relationship to 'modifies_cooldown' + a real magnitude when the
     * source's own referenced effect (found via the "effect#N" annotation on the Affecting
     * Spells/Modified By ref) is a known cooldown-modifier shape. Falls back to a bare 'modifies'
     * relationship (no magnitude) whenever the index is missing, the effect can't be found, or
     * its type isn't recognized — same "flag, don't guess" posture as categoryRelationshipMapping().
     *
     * Verified against two independent real cases before shipping, not a guess from the type
     * string alone: Paladin's Fist of Justice (spell_id 234299, effect#1) — 'Add Flat Modifier
     * (107): Spell Cooldown', Base Value -15000 — reduces Hammer of Justice from 45s to 30s,
     * confirmed against the player's own live in-game reading (2026-08-05). Independently, a
     * Warrior talent (spell_id 297945) declares the *same* -15000 cooldown reduction on Heroic
     * Leap through BOTH this plain marker AND the already-trusted 'Modify Recharge Time
     * (Category)' marker at once — i.e. this is confirmed to be the identical underlying game
     * mechanic as the Category-pass conversion already in production, not a new, unverified one.
     * Do not add more conversions here without an equally hand-verified worked example first.
     *
     * When the resolved effect is rank-scaled (SpellEffect.rank_op set — a multi-rank talent
     * whose magnitude differs per rank, e.g. Improved Fade: -5s at rank 1, -10s at rank 2, found
     * 2026-08-06 — see SpellDataFileParser's rank_scaling capture), the type is still confidently
     * known (the effect itself says so) but the magnitude deliberately is NOT computed here —
     * base_value only ever reflects one rank, and which rank any given build actually has isn't
     * an import-time fact at all, it's a per-build/per-viewer runtime one. Left null so
     * ModuleSpellReferenceService can compute the real number once it knows which rank the
     * current build selected (see effect_index, now persisted on every relationship row for
     * exactly this lookup).
     *
     * @return array{type: string, value: ?float, unit: ?string}
     */
    private function modifiesRelationshipMapping(Spell $source, ?int $effectIndex): array
    {
        $default = ['type' => 'modifies', 'value' => null, 'unit' => null];

        if ($effectIndex === null) {
            return $default;
        }

        $effect = SpellEffect::where('spell_id', $source->id)->where('effect_index', $effectIndex)->first();

        if (!$effect || $effect->type !== 'Add Flat Modifier (107): Spell Cooldown') {
            return $default;
        }

        if ($effect->rank_op !== null) {
            return ['type' => 'modifies_cooldown', 'value' => null, 'unit' => null];
        }

        return [
            'type' => 'modifies_cooldown',
            'value' => $effect->base_value !== null ? $effect->base_value / 1000 : null,
            'unit' => $effect->base_value !== null ? 'seconds' : null,
        ];
    }

    /**
     * Populates spell_relationships from the structural "Category" (spell-level) and "Affected
     * Spells (Category)" (effect-level) references captured by SpellDataFileParser — the
     * charge/cooldown-category equivalent of importRelationships()'s effect-value modifiers
     * above. Unlike Affecting Spells/Modified By, the two ends of a category relationship live on
     * *different* records (the target's own Category: line names its source(s); the source's own
     * effect names its target(s)) — see SpellDataFileParser's class docblock — so both directions
     * are read here and de-duped in memory before writing, making the pass robust to either side
     * of a pair being incomplete in the dump. Run once at the end for the same cross-class reason
     * as importRelationships().
     *
     * relationship_type/magnitude: only the source's own "Affected Spells (Category)" effect
     * direction carries the effect's own type/base_value — the target's "Category:" line never
     * does. Split by categoryRelationshipMapping() (see there — this is the fix for
     * game-data.md's "modifies_charges is a coarser label than the data actually supports"
     * finding, 2026-07-24, fixed 2026-08-01): "Category:"/"Affected Spells (Category)" is the
     * shared textual marker for 8 distinct SimC effect types, previously all lumped under
     * 'modifies_charges' regardless of which — meaning most rows tagged 'modifies_charges' before
     * this fix were actually cooldown-duration/rate/haste modifiers, not charge-count grants.
     * When a pair is only known via the non-magnitude-bearing "Category:" direction (no matching
     * effect found), the type can't be determined and falls back to the original 'modifies_charges'
     * label with no magnitude — see categoryRelationshipMapping()'s default case.
     *
     * Fixed 2026-08-10 — a real bug found investigating a live report (Balance Druid's Whirling
     * Stars talent showed no charge bonus on Celestial Alignment, despite +1 charge being right
     * there in the raw data). $pairs used to be keyed ONLY by "{source}|{target}", one scalar
     * $effect per key — so when a single source spell has MULTIPLE distinct Category effects
     * targeting the SAME spell (Whirling Stars' effect #2 "Modify Cooldown Charge" AND effect #3
     * "Modify Recharge Time" both target Celestial Alignment, confirmed directly in the raw dump
     * — same "Affected Spells (Category)" target list, different effect_index/type/base_value),
     * the second effect processed silently overwrote the first in the $pairs array before either
     * ever reached upsertTrack() — never a collision upsertTrack() itself could catch, since it
     * correctly keys on (source, target, relationship_type) and would have written both rows
     * fine if both had actually been passed to it. $pairs now collects a LIST of effects per
     * (source, target) pair instead of one; every effect in the list gets its own
     * categoryRelationshipMapping() call and its own upsertTrack() row, so two distinct
     * relationship_types for the same pair (a charges grant AND a cooldown reduction, as here)
     * both survive. A pair with only a bare "Category:" sighting (no matching effect at all)
     * keeps the exact same fallback behavior as before — an empty effects list still produces
     * one row via categoryRelationshipMapping(null)'s default case.
     */
    private function importCategoryRelationships(): void
    {
        $pairs = [];

        foreach ($this->pendingRelationshipRecords as $record) {
            foreach (array_keys($record['category_refs']) as $sourceExternalId) {
                $key = $sourceExternalId.'|'.$record['spell_id'];
                $pairs[$key] ??= [];
            }

            foreach ($record['effects'] as $effect) {
                foreach (array_keys($effect['affects_category']) as $targetExternalId) {
                    // Appends — a source spell can have more than one distinct Category effect
                    // (e.g. a charges grant AND a cooldown reduction) targeting the same spell;
                    // each must survive as its own relationship row, not overwrite the other.
                    $pairs[$record['spell_id'].'|'.$targetExternalId][] = $effect;
                }
            }
        }

        foreach ($pairs as $pair => $effects) {
            [$sourceExternalId, $targetExternalId] = array_map('intval', explode('|', $pair));

            $source = $this->spellIndex[$sourceExternalId] ?? null;
            $target = $this->spellIndex[$targetExternalId] ?? null;

            if (!$source || !$target || $source->id === $target->id) {
                $this->categoryRelationshipSkips++;

                continue;
            }

            // A bare category_refs sighting with no matching effect at all — same fallback as
            // before this fix, one row via the default (unknown-type) mapping.
            foreach ($effects === [] ? [null] : $effects as $effect) {
                $mapping = $this->categoryRelationshipMapping($effect);

                // See importRelationships()'s identical comment — modifier_value/unit are always
                // written explicitly, even as null, so a stale pre-rank-awareness magnitude gets
                // properly cleared rather than left in place by upsertTrack()'s fill-only-given-keys
                // behavior.
                $values = [
                    'effect_index' => $effect['effect_index'] ?? null,
                    'modifier_value' => $mapping['value'],
                    'modifier_unit' => $mapping['unit'],
                ];

                $this->upsertTrack(SpellRelationship::class, [
                    'source_spell_id' => $source->id,
                    'target_spell_id' => $target->id,
                    'relationship_type' => $mapping['type'],
                ], $values, 'spell_relationships');
            }
        }
    }

    /**
     * Maps a Category effect's own type string (SpellEffect.type, e.g. "Modify Cooldown Charge
     * (Category)") to the correctly-split relationship_type and, only where confidently derivable
     * from a hand-verified worked example, a computed magnitude — see game-data.md's effect-type
     * table for the full 8-way breakdown this replaces the single 'modifies_charges' label with.
     *
     * Only two of the eight convert to a magnitude:
     * - "Modify Cooldown Charge (Category)" -> +N charges (verified: Pain Suppression/Protector
     *   of the Frail, Base Value 1 == +1 charge, matching that talent's own Description text).
     * - "Modify Recharge Time (Category)" -> flat seconds (verified: the Mind Blast worked
     *   example, Base Value 19000 == 19 seconds, base_value/1000).
     * Every other type gets a correctly distinct relationship_type but no fabricated magnitude —
     * same "flag, don't guess" posture as everywhere else in this importer. Do not add more
     * conversions here without a hand-verified worked example first.
     *
     * A rank-scaled effect ($effect['rank_op'] set — a multi-rank talent whose magnitude differs
     * per rank, see SpellDataFileParser's rank_scaling capture, added 2026-08-06) still gets its
     * correct type here, but never a magnitude — base_value only reflects one rank, and which
     * rank a given build actually has is a per-build runtime fact, not an import-time one. Left
     * null so ModuleSpellReferenceService computes the real number once it knows the selected
     * rank (via effect_index, now persisted on every relationship row for this lookup).
     *
     * @param  ?array{type: ?string, base_value: ?float, rank_op: ?string}  $effect
     * @return array{type: string, value: ?float, unit: ?string}
     */
    private function categoryRelationshipMapping(?array $effect): array
    {
        $effectType = $effect['type'] ?? null;
        $baseValue = $effect['base_value'] ?? null;
        $rankScaled = ($effect['rank_op'] ?? null) !== null;

        return match ($effectType) {
            'Modify Cooldown Charge (Category)' => [
                'type' => 'modifies_charges',
                'value' => $rankScaled ? null : $baseValue,
                'unit' => (!$rankScaled && $baseValue !== null) ? 'charges' : null,
            ],
            'Modify Recharge Time (Category)' => [
                'type' => 'modifies_cooldown',
                'value' => ($rankScaled || $baseValue === null) ? null : $baseValue / 1000,
                'unit' => (!$rankScaled && $baseValue !== null) ? 'seconds' : null,
            ],
            'Modify Recharge Time% (Category)', 'Modify Cooldown Time (Category)' => [
                'type' => 'modifies_cooldown',
                'value' => null,
                'unit' => null,
            ],
            'Modify Charge Cooldown Recharge Rate% (Category)' => [
                'type' => 'modifies_charge_rate',
                'value' => null,
                'unit' => null,
            ],
            'Hasted Cooldown Duration (Category)', 'Hasted Cooldown Regeneration (Category)' => [
                'type' => 'hasted_cooldown',
                'value' => null,
                'unit' => null,
            ],
            'Ignore Spell Charge Cooldown (Category)' => [
                'type' => 'bypasses_cooldown',
                'value' => null,
                'unit' => null,
            ],
            // Type unknown — only the target-side "Category:" ref exists for this pair, with no
            // matching source-side "Affected Spells (Category)" effect to identify it (see
            // importCategoryRelationships()'s de-dup comment above). Falls back to the original,
            // pre-split label rather than guessing which of the 8 specific types it is.
            default => [
                'type' => 'modifies_charges',
                'value' => null,
                'unit' => null,
            ],
        };
    }

    /**
     * Populates spell_relationships (relationship_type = 'replaces') from the "replace=" annotation
     * on Talent Entry lines — e.g. Beacon of Virtue's own Talent Entry line has
     * replace="Beacon of Light" (id=53563), meaning selecting that talent replaces Beacon of Light
     * with Beacon of Virtue in the action bar. Unlike importCategoryRelationships(), both ends are
     * declared on the *same* record — the talent's own spell_id is unambiguously the source, no
     * cross-record lookup needed — closer to importRelationships()'s pattern. Still run once at the
     * end, after every class's spells are imported, since the replaced spell can live in a
     * different class file (e.g. a shared/baseline spell).
     */
    private function importReplacesRelationships(): void
    {
        foreach ($this->pendingRelationshipRecords as $record) {
            $source = $this->spellIndex[$record['spell_id']] ?? null;

            if (!$source) {
                continue;
            }

            foreach (array_keys($record['replaces_refs']) as $targetExternalId) {
                $target = $this->spellIndex[$targetExternalId] ?? null;

                if (!$target || $source->id === $target->id) {
                    $this->replacesRelationshipSkips++;

                    continue;
                }

                $this->upsertTrack(SpellRelationship::class, [
                    'source_spell_id' => $source->id,
                    'target_spell_id' => $target->id,
                    'relationship_type' => 'replaces',
                ], [], 'spell_relationships');
            }
        }
    }

    /**
     * Populates spell_relationships (relationship_type = 'modifies_cooldown') by regex-parsing
     * PvP talent descriptions for the one confirmed phrasing shape — e.g. Ultimate Radiance's
     * "Evangelism cooldown is reduced by 45 sec and Power Word: Radiance healing is increased by
     * 15%." yields one match (Evangelism, -45 seconds); the healing clause is not this shape and
     * is correctly ignored. Unlike the other three relationship passes, PvP talent JSON carries
     * no structured spell_id references at all (see class docblock) — this is the one
     * relationship type sourced from free text, so it stays deliberately conservative: only the
     * two known "cooldown is reduced/increased by N sec/%" shapes are matched, everything else
     * is logged and skipped rather than guessed. Run once at the end, after every class's spells
     * (and therefore every class's own Spell rows) exist, since name resolution below queries the
     * DB directly rather than the in-memory spellIndex (which is keyed by spell_id, not name).
     */
    private function importPvpTalentRelationships(): void
    {
        foreach ($this->pendingPvpTalentRecords as $record) {
            $source = $this->spellIndex[$record['spell_id']] ?? null;

            if (!$source) {
                $this->pvpTalentRelationshipSkips++;

                continue;
            }

            if (!preg_match_all(
                '/([A-Z][\w:\'\- ]*?) cooldown is (reduced|increased) by ([\d.]+)\s*(sec(?:onds)?|%)/i',
                $record['description'],
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $match) {
                $name = trim($match[1]);
                $sign = strtolower($match[2]) === 'reduced' ? -1 : 1;
                $magnitude = (float) $match[3];
                $unit = str_contains($match[4], '%') ? 'percent' : 'seconds';

                $target = $this->resolvePvpTalentTargetSpell($name, $record['class_id']);

                if (!$target || $target->id === $source->id) {
                    $this->pvpTalentRelationshipSkips++;
                    Log::warning('ImportSpellData: could not resolve pvp talent cooldown-modifier target spell', [
                        'pvp_talent_spell_id' => $record['spell_id'],
                        'target_name' => $name,
                        'class_id' => $record['class_id'],
                    ]);

                    continue;
                }

                $this->pvpTalentRelationshipMatches++;

                $this->upsertTrack(SpellRelationship::class, [
                    'source_spell_id' => $source->id,
                    'target_spell_id' => $target->id,
                    'relationship_type' => 'modifies_cooldown',
                ], [
                    'description' => $match[0],
                    'modifier_value' => $sign * $magnitude,
                    'modifier_unit' => $unit,
                ], 'spell_relationships');
            }
        }
    }

    /**
     * Resolves a name parsed out of a PvP talent description to a real Spell, scoped to the
     * talent's own class (a PvP talent can only affect a spell its own class actually has).
     * Not expected to be perfect on a duplicate-name collision — same "not a system failure"
     * posture as ModuleSpellReferenceService::resolveSpellByName(), which this mirrors in
     * miniature: prefer a class-scoped match that actually carries cooldown/charge data (the
     * spell most likely to be a real cooldown target), else the first match.
     */
    private function resolvePvpTalentTargetSpell(string $name, int $classId): ?Spell
    {
        $candidates = Spell::where('name', $name)
            ->whereHas('classAvailability', fn ($q) => $q->where('class_id', $classId))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->first(fn (Spell $c) => $c->cooldown_seconds !== null || $c->charges !== null)
            ?? $candidates->first();
    }

    /**
     * Backfills descriptions that were only a "$@spelldesc<id>" pointer at parse time (see
     * SpellDataFileParser) — ~44% of all Description lines across this dataset are this pointer
     * form, not literal text. Follows chains (a pointer can point at another pointer) up to a
     * bounded depth with cycle protection, since nothing in the source data guarantees a chain
     * terminates in one hop. Run once at the end, after every class's spells are imported, since
     * the referenced spell can live in a different class file than the one pointing at it.
     */
    private function resolveDescriptionReferences(): void
    {
        foreach ($this->pendingDescriptionRefs as $spellExternalId => $refExternalId) {
            $target = $this->spellIndex[$spellExternalId] ?? null;

            if (!$target) {
                continue;
            }

            $resolved = null;
            $visited = [$spellExternalId => true];
            $current = $refExternalId;

            for ($hop = 0; $hop < 5; $hop++) {
                if (isset($visited[$current])) {
                    // Cycle — bail without a resolved value rather than loop forever.
                    $resolved = null;
                    break;
                }
                $visited[$current] = true;

                $next = $this->spellIndex[$current] ?? null;

                if (!$next) {
                    $resolved = null;
                    break;
                }

                if ($next->description !== null) {
                    $resolved = $next->description;
                    break;
                }

                // This spell's own description is itself unresolved at parse time — but by now
                // (after all classes are imported) its description column may already hold a
                // backfilled value from an earlier iteration of this same loop, or it may still
                // be a pointer we haven't processed yet. Follow the chain via pendingDescriptionRefs.
                if (!isset($this->pendingDescriptionRefs[$current])) {
                    $resolved = null;
                    break;
                }

                $current = $this->pendingDescriptionRefs[$current];
            }

            if ($resolved === null) {
                $this->descriptionRefSkips++;

                continue;
            }

            $this->upsertTrack(Spell::class, [
                'patch_id' => $target->patch_id,
                'spell_id' => $target->spell_id,
            ], [
                'description' => $resolved,
            ], 'spells');
        }
    }

    /**
     * Applies data/spelldata/baseline-spec-overrides.txt — see that file's own header for the
     * full rationale. Runs unconditionally (independent of --only), since it's a small,
     * fast, additive pass reading a hand-curated list, not tied to any one class's import
     * step; resolves class/spec/spell directly against the DB rather than this run's
     * transient in-memory maps, so it works correctly even when --only scopes the class loop
     * to a subset that doesn't include an override's class.
     *
     * Each valid line becomes an explicit-spec_id spell_class_availability row with
     * source='verified_override' — added alongside (never replacing) any existing
     * spec_id=NULL row for the same spell, so this can never narrow or remove what the
     * spec_id=NULL bucket already (ambiguously) claims. A malformed line, or one naming a
     * spell/class/spec that doesn't resolve against this patch, is skipped and counted —
     * never silently guessed at.
     */
    /**
     * Spells absent from SimC's own raw dump entirely (confirmed, not a fetch/filter bug —
     * see data/spelldata/manual-spells.txt's own header for the Polymorph case that started
     * this) — created directly here rather than annotated the way
     * importBaselineSpecOverrides() annotates an existing row, since there is no existing row.
     * Writes spell_class_availability as source='verified_override' immediately, skipping the
     * ambiguous baseline/spec_id=NULL step entirely — we already know true spec ownership
     * because we're asserting it by hand, not inferring it from incomplete source data.
     */
    private function importManualSpells(Patch $patch): void
    {
        $path = base_path('data/spelldata/manual-spells.txt');

        if (!File::exists($path)) {
            return;
        }

        foreach ($this->parseManualSpellBlocks(File::get($path)) as $block) {
            $required = ['spell_id', 'name', 'class', 'specs'];
            $missing = array_filter($required, fn (string $key) => ($block[$key] ?? '') === '');

            if ($missing !== []) {
                $this->manualSpellsSkipped++;
                $this->warn('  Skipping malformed manual-spells.txt block (missing '.implode(', ', $missing).'): '.($block['name'] ?? 'unknown'));

                continue;
            }

            $class = GameClass::where('slug', $block['class'])->first();

            if (!$class) {
                $this->manualSpellsSkipped++;
                $this->warn("  Skipping manual-spells.txt block '{$block['name']}' — unknown class slug '{$block['class']}'.");

                continue;
            }

            $specSlugs = $block['specs'] === 'all'
                ? Specialization::where('class_id', $class->id)->pluck('slug')->all()
                : array_map('trim', explode(',', $block['specs']));

            $specs = Specialization::where('class_id', $class->id)->whereIn('slug', $specSlugs)->get();

            if ($specs->isEmpty()) {
                $this->manualSpellsSkipped++;
                $this->warn("  Skipping manual-spells.txt block '{$block['name']}' — no specs resolved from '{$block['specs']}' for class '{$block['class']}'.");

                continue;
            }

            $spell = $this->upsertTrack(Spell::class, [
                'patch_id' => $patch->id,
                'spell_id' => (int) $block['spell_id'],
            ], [
                'name' => $block['name'],
                'school' => $block['school'] ?? null,
                'description' => $block['description'] ?? null,
                'cooldown_seconds' => ($block['cooldown_seconds'] ?? '') !== '' ? (float) $block['cooldown_seconds'] : null,
                'duration_seconds' => ($block['duration_seconds'] ?? '') !== '' ? (float) $block['duration_seconds'] : null,
                'charges' => ($block['charges'] ?? '') !== '' ? (int) $block['charges'] : null,
                'mechanic' => $block['mechanic'] ?? null,
                'spell_type' => $block['spell_type'] ?? null,
                'range_yards' => $block['range_yards'] ?? null,
                'is_passive' => false,
                'not_in_spellbook' => false,
            ], 'spells');

            $this->spellIndex[(int) $block['spell_id']] = $spell;

            foreach ($specs as $spec) {
                $this->upsertTrack(SpellClassAvailability::class, [
                    'spell_id' => $spell->id,
                    'class_id' => $class->id,
                    'spec_id' => $spec->id,
                    'source' => 'verified_override',
                ], [], 'spell_class_availability');
            }

            $this->manualSpellsApplied++;
        }
    }

    /** @return array<int, array<string, string>> */
    private function parseManualSpellBlocks(string $content): array
    {
        $blocks = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($trimmed === 'BEGIN') {
                $current = [];

                continue;
            }

            if ($trimmed === 'END') {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = null;

                continue;
            }

            if ($current === null || !str_contains($trimmed, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $trimmed, 2);
            $current[trim($key)] = trim($value);
        }

        return $blocks;
    }

    private function importBaselineSpecOverrides(Patch $patch): void
    {
        $path = base_path('data/spelldata/baseline-spec-overrides.txt');

        if (!File::exists($path)) {
            return;
        }

        // Tracks every (class, spec, resolved display name) seen this run so two DIFFERENT
        // spell_ids covering the identical name+spec can be caught and warned about — this is
        // exactly the mistake that shipped 2026-08-08 (Kidney Shot's real spell_id 408 AND its
        // dataless duplicate 426589 were both added for all 3 Rogue specs, rendering as two
        // visible rows for one ability). Added 2026-08-10 as a standing safety net so this class
        // of bug surfaces at import time instead of via a user report.
        $seenPerSpec = [];

        // Every (spell.id, spec.id) pair this run actually wrote — used below to prune any
        // stale verified_override row this patch has that ISN'T covered by the file anymore.
        // Added 2026-08-19: upsertTrack() only ever creates/updates, never deletes, so removing
        // a line from this file (e.g. correcting a wrong-spell_id mistake, see the "hidden
        // duplicate copy" fixes in this same file's history) previously left the old row
        // permanently in the DB — a real production incident: Wild Charge kept rendering
        // duplicated even after the bad override line was deleted and re-imported, because the
        // stale row from the earlier import never got cleaned up. This closes that gap
        // permanently, the same "always run this defensive pass" precedent as
        // cleanupSamePositionCollisions()/cleanupClassTreeBloat() elsewhere in this file.
        $writtenPairs = [];

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 3 || !ctype_digit($parts[0])) {
                $this->baselineOverrideSkips++;
                $this->warn("  Skipping malformed baseline-spec-overrides.txt line: {$line}");

                continue;
            }

            [$externalSpellId, $classSlug, $specSlug] = $parts;

            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', (int) $externalSpellId)->first();
            $class = GameClass::where('slug', $classSlug)->first();
            $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first() : null;

            if (!$spell || !$class || !$spec) {
                $this->baselineOverrideSkips++;
                $this->warn("  Skipping unresolved baseline-spec-overrides.txt line (spell/class/spec not found for this patch): {$line}");

                continue;
            }

            $this->upsertTrack(SpellClassAvailability::class, [
                'spell_id' => $spell->id,
                'class_id' => $class->id,
                'spec_id' => $spec->id,
                'source' => 'verified_override',
            ], [], 'spell_class_availability');

            $this->baselineOverridesApplied++;
            $writtenPairs["{$spell->id}:{$spec->id}"] = true;

            $seenPerSpec["{$class->id}:{$spec->id}:{$spell->name}"][] = (int) $externalSpellId;
        }

        $stalePruned = 0;
        SpellClassAvailability::where('source', 'verified_override')
            ->whereHas('spell', fn ($q) => $q->where('patch_id', $patch->id))
            ->get(['id', 'spell_id', 'spec_id'])
            ->each(function (SpellClassAvailability $row) use ($writtenPairs, &$stalePruned) {
                if (!isset($writtenPairs["{$row->spell_id}:{$row->spec_id}"])) {
                    $row->delete();
                    $stalePruned++;
                }
            });

        if ($stalePruned > 0) {
            $this->warn("  Pruned {$stalePruned} stale verified_override row(s) no longer present in baseline-spec-overrides.txt.");
        }

        foreach ($seenPerSpec as $key => $spellIds) {
            $distinctIds = array_unique($spellIds);

            if (count($distinctIds) > 1) {
                [$classId, $specId, $name] = explode(':', $key, 3);
                $this->warn("  ⚠ DUPLICATE OVERRIDE: '{$name}' has ".count($distinctIds).
                    ' different spell_ids ('.implode(', ', $distinctIds).
                    ") both overridden for the same class_id={$classId}/spec_id={$specId} — this will render as two rows for one ability. ".
                    'Pick a single correct spell_id per (name, spec) in baseline-spec-overrides.txt.');
            }
        }
    }

    /**
     * Applies data/spelldata/cc-synergies-overrides.txt — see that file's own header for the
     * full story of why it exists (a real deploy gap found 2026-08-11: dr_category/chain_target/
     * is_peel/is_interrupt/pvp_duration_seconds had only ever been written directly to the local
     * dev DB, never captured anywhere a fresh `import:spelldata` on another environment could
     * pick them up). This file is now the single source of truth for those five columns — every
     * field is written on every run (blank means null/false, not "leave alone"), so editing or
     * removing a line here and re-importing is how a correction actually propagates everywhere.
     */
    private function importCcSynergyOverrides(Patch $patch): void
    {
        $path = base_path('data/spelldata/cc-synergies-overrides.txt');

        if (!File::exists($path)) {
            return;
        }

        $validDrCategories = ['Stun', 'Disorient', 'Incapacitate', 'Root', 'Silence', 'Knockback', 'Disarm', 'Slow'];
        $validChainTargets = ['healer', 'kill_target', 'both'];

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 10));

            if (count($parts) < 6 || !ctype_digit($parts[0])) {
                $this->ccSynergyOverrideSkips++;
                $this->warn("  Skipping malformed cc-synergies-overrides.txt line: {$line}");

                continue;
            }

            [$externalSpellId, $drCategory, $chainTarget, $isPeel, $isInterrupt, $pvpDuration] = $parts;
            // pairs_with_category — added 2026-08-23, 7th data field. requires_stealth /
            // requires_target_out_of_combat — added 2026-08-23, 8th/9th data fields. All three
            // optional/backward-compatible: an older line without them simply has no requirement
            // recorded, same as every other blank field in this file.
            $pairsWithCategory = $parts[6] ?? '';
            $requiresStealth = $parts[7] ?? '';
            $requiresTargetOutOfCombat = $parts[8] ?? '';

            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', (int) $externalSpellId)->first();

            if (!$spell) {
                $this->ccSynergyOverrideSkips++;
                $this->warn("  Skipping unresolved cc-synergies-overrides.txt line (spell not found for this patch): {$line}");

                continue;
            }

            if ($drCategory !== '' && !in_array($drCategory, $validDrCategories, true)) {
                $this->ccSynergyOverrideSkips++;
                $this->warn("  Skipping cc-synergies-overrides.txt line with unknown dr_category '{$drCategory}': {$line}");

                continue;
            }

            if ($chainTarget !== '' && !in_array($chainTarget, $validChainTargets, true)) {
                $this->ccSynergyOverrideSkips++;
                $this->warn("  Skipping cc-synergies-overrides.txt line with unknown chain_target '{$chainTarget}': {$line}");

                continue;
            }

            if ($pairsWithCategory !== '' && !in_array($pairsWithCategory, $validDrCategories, true)) {
                $this->ccSynergyOverrideSkips++;
                $this->warn("  Skipping cc-synergies-overrides.txt line with unknown pairs_with_category '{$pairsWithCategory}': {$line}");

                continue;
            }

            // Standing rule (see CcChainBuilder's class docblock) — kill_target/both may only
            // ever be paired with Stun/Silence, since those are the only categories that don't
            // break on damage. Warn, don't silently accept, if a future edit violates this.
            if (in_array($chainTarget, ['kill_target', 'both'], true) && !in_array($drCategory, ['Stun', 'Silence'], true)) {
                $this->warn("  ⚠ cc-synergies-overrides.txt: chain_target='{$chainTarget}' with dr_category='{$drCategory}' violates the break-on-damage rule (only Stun/Silence may be kill_target/both): {$line}");
            }

            // Standing rule, added 2026-08-17 after a real mistake (Censure — a passive that
            // changes Holy Word: Chastise's CC type — was wrongly tagged as its own Stun,
            // rendering as an independent CC ability in the Synergies tab). A dr_category
            // represents an actively-cast ability a player can sequence into a CC chain; a
            // passive, by definition, isn't cast, so it should never carry one. Warn, don't
            // silently accept — same posture as the break-on-damage rule above, not a hard skip,
            // since this is a strong signal something's wrong rather than an absolute
            // impossibility worth blocking outright.
            if ($drCategory !== '' && $spell->is_passive) {
                $this->warn("  ⚠ cc-synergies-overrides.txt: dr_category='{$drCategory}' assigned to a PASSIVE spell ('{$spell->name}') — passives can't be cast/sequenced in a CC chain; this is almost always a sign the tag belongs on an active ability this passive modifies instead: {$line}");
            }

            $this->upsertTrack(Spell::class, ['id' => $spell->id], [
                'dr_category' => $drCategory !== '' ? $drCategory : null,
                'chain_target' => $chainTarget !== '' ? $chainTarget : null,
                'is_peel' => $isPeel === '1',
                'is_interrupt' => $isInterrupt === '1',
                'pvp_duration_seconds' => $pvpDuration !== '' ? (float) $pvpDuration : null,
                'pairs_with_category' => $pairsWithCategory !== '' ? $pairsWithCategory : null,
                'requires_stealth' => $requiresStealth === '1',
                'requires_target_out_of_combat' => $requiresTargetOutOfCombat === '1',
            ], 'spells');

            $this->ccSynergyOverridesApplied++;
        }
    }

    /**
     * Reads data/spelldata/scalar-corrections.txt — see that file's own header for the full
     * rationale. Field-level patch only: a spell must already exist for this patch (created via
     * the normal SimC import, PvP-talent import, or importManualSpells() above), and only the
     * fields actually given on the line are touched — omitting a field leaves whatever's already
     * there alone, unlike importCcSynergyOverrides() above which always writes all five of its
     * own fields explicitly. Never creates a spell or touches spell_class_availability — this is
     * strictly narrower than manual-spells.txt on purpose (see this method's own header note on
     * why reusing that file would have created duplicate availability rows for a spell that's
     * already correctly available).
     */
    private function importScalarCorrections(Patch $patch): void
    {
        $path = base_path('data/spelldata/scalar-corrections.txt');

        if (!File::exists($path)) {
            return;
        }

        $validFields = ['cooldown_seconds', 'duration_seconds', 'mechanic', 'spell_type', 'range_yards', 'cast_type'];
        $stringFields = ['mechanic', 'spell_type', 'range_yards', 'cast_type'];
        $validCastTypes = ['instant', 'cast'];

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));

            if (count($parts) < 2 || !ctype_digit($parts[0])) {
                $this->scalarCorrectionSkips++;
                $this->warn("  Skipping malformed scalar-corrections.txt line: {$line}");

                continue;
            }

            [$externalSpellId, $fieldsRaw] = $parts;

            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', (int) $externalSpellId)->first();

            if (!$spell) {
                $this->scalarCorrectionSkips++;
                $this->warn("  Skipping unresolved scalar-corrections.txt line (spell not found for this patch — this file only patches existing spells, it never creates one): {$line}");

                continue;
            }

            $values = [];
            $malformed = false;

            foreach (explode(',', $fieldsRaw) as $pair) {
                $pair = trim($pair);

                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }

                [$field, $value] = array_map('trim', explode('=', $pair, 2));

                if (!in_array($field, $validFields, true)) {
                    $this->warn("  Skipping unknown scalar-corrections.txt field '{$field}': {$line}");
                    $malformed = true;

                    continue;
                }

                if ($field === 'cast_type' && !in_array($value, $validCastTypes, true)) {
                    $this->warn("  Skipping scalar-corrections.txt line with unknown cast_type '{$value}' (must be instant or cast): {$line}");
                    $malformed = true;

                    continue;
                }

                $values[$field] = in_array($field, $stringFields, true) ? $value : (float) $value;
            }

            if ($malformed || $values === []) {
                $this->scalarCorrectionSkips++;

                continue;
            }

            $this->upsertTrack(Spell::class, ['id' => $spell->id], $values, 'spells');

            $this->scalarCorrectionsApplied++;
        }
    }

    private function resolveOrCreateSpell(int $patchId, int $externalSpellId, string $name, ?string $description = null): Spell
    {
        if (isset($this->spellIndex[$externalSpellId])) {
            return $this->spellIndex[$externalSpellId];
        }

        $spell = $this->upsertTrack(Spell::class, [
            'patch_id' => $patchId,
            'spell_id' => $externalSpellId,
        ], [
            'name' => $name,
            'description' => $description,
        ], 'spells');

        $this->spellIndex[$externalSpellId] = $spell;

        return $spell;
    }

    /**
     * Scans hero-*.txt files directly for spec eligibility — not routed through
     * SpellDataFileParser's per-spell-record shape, since this is a per-*file* aggregate,
     * not a property of any individual spell. Every "tree=hero" Talent Entry line (confirmed
     * 100% consistent across 753 occurrences on 2026-07-25) carries a "(SpecList)" qualifier
     * immediately after the hero tree's name — e.g. "Archon (Holy) [tree=hero, ...]" — the
     * union of every spec name seen across a file is that hero tree's full eligible-spec set.
     *
     * Exists because Blizzard's Game Data API can't answer this: /data/wow/talent-tree/{id}/
     * playable-specialization/{specId} returns the identical hero_talent_trees list regardless
     * of which spec is in the URL (confirmed live, 2026-07-25) — the API doesn't scope that
     * field by spec at all, so re-fetching can't fix it. This is a real API limitation, not a
     * bug in fetch-talent-trees.php.
     *
     * @return array<string, array<int, string>> normalizeSlug(hero tree name) => spec names
     */
    private function scanHeroTreeSpecs(string $classDir): array
    {
        $result = [];

        foreach (File::glob($classDir.'/hero-*.txt') as $file) {
            $content = File::get($file);

            if (!preg_match_all('/\(([^)]+)\)\s*\[[^\]]*\btree=hero\b/', $content, $matches)) {
                continue;
            }

            $specs = [];
            foreach ($matches[1] as $specListText) {
                foreach (explode(',', $specListText) as $specName) {
                    $specs[trim($specName)] = true;
                }
            }

            $heroTreeNameSlug = $this->normalizeSlug(preg_replace('/^hero-?/i', '', pathinfo($file, PATHINFO_FILENAME)));
            $result[$heroTreeNameSlug] = array_keys($specs);
        }

        return $this->applyHeroTreeSpecOverrides($classDir, $result);
    }

    /**
     * Applies data/spelldata/hero-tree-spec-overrides.txt on top of scanHeroTreeSpecs()'s raw
     * SimC-dump-derived result — for the rare case the dump's own "(SpecList)" tag is simply
     * wrong, not a parsing problem on our side. Found 2026-08-15: Demon Hunter's Fel-Scarred and
     * Void-Scarred hero-*.txt files both tag "(Havoc, Devourer)" and Annihilator tags
     * "(Generic)" (not a real spec name — silently dropped by the lookup this method already
     * does), all three confirmed wrong by the user directly (real, current game knowledge —
     * Devourer is brand-new content, plausibly why the community-maintained SimC dump hasn't
     * caught up). Same "verify by hand, one line at a time, never bulk-derive" discipline as
     * baseline-spec-overrides.txt — an override REPLACES the scanned spec list for that hero
     * tree entirely, it doesn't merge, since the whole point is the scanned list was wrong.
     *
     * Scoped by classSlug so this can't silently apply to the wrong class if the same hero-tree
     * name slug ever collided across classes (not expected, but cheap to guard against).
     */
    private function applyHeroTreeSpecOverrides(string $classDir, array $result): array
    {
        $path = base_path('data/spelldata/hero-tree-spec-overrides.txt');

        if (!File::exists($path)) {
            return $result;
        }

        $classSlug = $this->normalizeSlug(basename($classDir));

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 3) {
                $this->warn("  Malformed hero-tree-spec-overrides.txt line, skipping: {$line}");

                continue;
            }

            [$overrideClassSlug, $heroTreeSlug, $specListText] = $parts;

            if ($this->normalizeSlug($overrideClassSlug) !== $classSlug) {
                continue;
            }

            $result[$this->normalizeSlug($heroTreeSlug)] = array_map('trim', explode(',', $specListText));
        }

        return $result;
    }

    private function loadMatchingJson(string $dir, string $classFolderName): ?array
    {
        if (!File::isDirectory($dir)) {
            return null;
        }

        $target = $this->normalizeSlug($classFolderName);

        foreach (File::glob($dir.'/*.json') as $file) {
            if ($this->normalizeSlug(pathinfo($file, PATHINFO_FILENAME)) !== $target) {
                continue;
            }

            try {
                return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->warn("  Failed to parse JSON {$file}: {$e->getMessage()}");

                return null;
            }
        }

        return null;
    }

    private function normalizeSlug(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Generic upsert with created/updated/unchanged tracking. $unique must contain every column
     * in the model's DB-level unique constraint — the model's $fillable must include them, since
     * firstOrNew() mass-assigns $unique through the constructor when no row matches.
     */
    private function upsertTrack(string $modelClass, array $unique, array $values, string $table): Model
    {
        /** @var Model $instance */
        $instance = $modelClass::firstOrNew($unique);
        $existed = $instance->exists;

        $instance->fill($values);
        $changed = $instance->isDirty();
        $instance->save();

        if (!$existed) {
            $this->counts[$table]['created']++;
        } elseif ($changed) {
            $this->counts[$table]['updated']++;
        } else {
            $this->counts[$table]['unchanged']++;
        }

        return $instance;
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('Import summary:');

        $rows = [];
        foreach ($this->counts as $table => $c) {
            $rows[] = [$table, $c['created'], $c['updated'], $c['unchanged']];
        }
        $this->table(['Table', 'Created', 'Updated', 'Unchanged'], $rows);

        if ($this->relationshipSkips > 0) {
            $this->comment("Skipped {$this->relationshipSkips} spell_relationships reference(s) whose source spell wasn't found in this patch.");
        }

        if ($this->categoryRelationshipSkips > 0) {
            $this->comment("Skipped {$this->categoryRelationshipSkips} modifies_charges reference(s) whose source or target spell wasn't found in this patch.");
        }

        if ($this->replacesRelationshipSkips > 0) {
            $this->comment("Skipped {$this->replacesRelationshipSkips} replaces reference(s) whose target spell wasn't found in this patch.");
        }

        if ($this->heroTreeSpecSkips > 0) {
            $this->comment("Skipped {$this->heroTreeSpecSkips} hero-tree spec reference(s) whose spec name didn't match a known specialization.");
        }

        if ($this->descriptionRefSkips > 0) {
            $this->comment("Left {$this->descriptionRefSkips} description(s) unresolved — the referenced spell wasn't found, or the reference chain exceeded the 5-hop limit.");
        }

        if ($this->pvpTalentRelationshipMatches > 0 || $this->pvpTalentRelationshipSkips > 0) {
            $this->comment("PvP talent cooldown modifiers: {$this->pvpTalentRelationshipMatches} matched, {$this->pvpTalentRelationshipSkips} skipped (target spell not resolved).");
        }

        if ($this->baselineOverridesApplied > 0 || $this->baselineOverrideSkips > 0) {
            $this->comment("Baseline spec overrides: {$this->baselineOverridesApplied} applied, {$this->baselineOverrideSkips} skipped (see warnings above).");
        }

        if ($this->manualSpellsApplied > 0 || $this->manualSpellsSkipped > 0) {
            $this->comment("Manual spells: {$this->manualSpellsApplied} applied, {$this->manualSpellsSkipped} skipped (see warnings above).");
        }

        if ($this->scalarCorrectionsApplied > 0 || $this->scalarCorrectionSkips > 0) {
            $this->comment("Scalar corrections (cooldown_seconds/duration_seconds/mechanic): {$this->scalarCorrectionsApplied} applied, {$this->scalarCorrectionSkips} skipped (see warnings above).");
        }

        if ($this->ccSynergyOverridesApplied > 0 || $this->ccSynergyOverrideSkips > 0) {
            $this->comment("CC synergy overrides (dr_category/chain_target/is_peel/is_interrupt/pvp_duration_seconds): {$this->ccSynergyOverridesApplied} applied, {$this->ccSynergyOverrideSkips} skipped (see warnings above).");
        }
    }

    /**
     * Runs unconditionally on every import, same "always run this defensive pass" precedent as
     * cleanupSamePositionCollisions() above — moved here from PatchUpdate's own step 6/6
     * (2026-08-13) so this check fires on a plain `import:spelldata` re-run too, not only when
     * going through the full wow:patch-update orchestrator. Motivation: this session's own
     * workflow ran import:spelldata directly (an override-file tweak, not a full patch update)
     * several times without ever re-checking against real spellbook snapshots — the exact gap
     * this closes. PatchUpdate no longer duplicates this step; see its own docblock.
     *
     * Report-only, same as before — never writes anything, just surfaces drift for a human to
     * act on. Silently does nothing when no snapshots are on file (a fresh/CI environment),
     * matching PatchUpdate's original behavior exactly.
     */
    private function runSpellbookDiffCheck(): void
    {
        $snapshotIds = SpellbookSnapshot::pluck('id');

        if ($snapshotIds->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info('Sanity-checking against real spellbook snapshots on file:');
        foreach ($snapshotIds as $id) {
            $this->line("  --- snapshot #{$id} ---");
            $this->call('wow:diff-spellbook', ['snapshot_id' => $id]);
        }
    }
}
