<?php

namespace App\Console\Commands;

use App\Http\Services\SpellDataFileParser;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
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
 * spell_relationships carries three distinct relationship_type values: 'modifies' (effect-value
 * modifiers, from Affecting Spells/Modified By — importRelationships()), 'modifies_charges'
 * (charge-count modifiers, from Category/Affected Spells (Category) — importCategoryRelationships()),
 * and 'replaces' (action-bar spell replacement, from Talent Entry's replace= annotation —
 * importReplacesRelationships()).
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

    private int $heroTreeSpecSkips = 0;

    /** @var array<int, int> external spell_id => the external spell_id its description points at */
    private array $pendingDescriptionRefs = [];

    private int $descriptionRefSkips = 0;

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
        $this->resolveDescriptionReferences();

        $this->printSummary();

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
                $specIds = $source === 'baseline'
                    ? $this->resolveBaselineSpecIds($record['class_field'], $class->name, $classSpecs)
                    : [$specId];

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
            }
        }
    }

    /**
     * Populates spell_relationships from the structural "Affecting Spells" (spell-level) and
     * "Modified By" (effect-level) references captured by SpellDataFileParser — both list the
     * *other* spell that modifies the current record as an explicit id, not free text. Run once
     * at the end, after every class's spells have been imported, since a modifier can live in a
     * different class file than the spell it modifies (e.g. a shared/baseline entry).
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

            foreach (array_keys($sources) as $sourceExternalId) {
                $source = $this->spellIndex[$sourceExternalId] ?? null;

                if (!$source || $source->id === $target->id) {
                    $this->relationshipSkips++;

                    continue;
                }

                $this->upsertTrack(SpellRelationship::class, [
                    'source_spell_id' => $source->id,
                    'target_spell_id' => $target->id,
                    'relationship_type' => 'modifies',
                ], [], 'spell_relationships');
            }
        }
    }

    /**
     * Populates spell_relationships (relationship_type = 'modifies_charges') from the structural
     * "Category" (spell-level) and "Affected Spells (Category)" (effect-level) references
     * captured by SpellDataFileParser — the charge-count equivalent of importRelationships()'s
     * effect-value modifiers above. Unlike Affecting Spells/Modified By, the two ends of a
     * category relationship live on *different* records (the target's own Category: line names
     * its source(s); the source's own effect names its target(s)) — see SpellDataFileParser's
     * class docblock — so both directions are read here and de-duped in memory before writing,
     * making the pass robust to either side of a pair being incomplete in the dump. Run once at
     * the end for the same cross-class reason as importRelationships().
     */
    private function importCategoryRelationships(): void
    {
        $pairs = [];

        foreach ($this->pendingRelationshipRecords as $record) {
            foreach (array_keys($record['category_refs']) as $sourceExternalId) {
                $pairs[$sourceExternalId.'|'.$record['spell_id']] = true;
            }

            foreach ($record['effects'] as $effect) {
                foreach (array_keys($effect['affects_category']) as $targetExternalId) {
                    $pairs[$record['spell_id'].'|'.$targetExternalId] = true;
                }
            }
        }

        foreach (array_keys($pairs) as $pair) {
            [$sourceExternalId, $targetExternalId] = array_map('intval', explode('|', $pair));

            $source = $this->spellIndex[$sourceExternalId] ?? null;
            $target = $this->spellIndex[$targetExternalId] ?? null;

            if (!$source || !$target || $source->id === $target->id) {
                $this->categoryRelationshipSkips++;

                continue;
            }

            $this->upsertTrack(SpellRelationship::class, [
                'source_spell_id' => $source->id,
                'target_spell_id' => $target->id,
                'relationship_type' => 'modifies_charges',
            ], [], 'spell_relationships');
        }
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
    }
}
