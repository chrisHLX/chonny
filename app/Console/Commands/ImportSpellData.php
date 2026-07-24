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
            $this->importTalentTrees($class, $patch, $classSpecs, $treeJson);
        }

        if ($pvpJson !== null) {
            $this->importPvpTalents($class, $patch, $classSpecs, $pvpJson);
        } else {
            $this->warn("  No matching pvp talent JSON for '{$folderName}' — skipping pvp talents.");
        }
    }

    /**
     * Classifies a spelldata .txt filename into a spell_class_availability (source, spec_id)
     * pair. baseline.txt and class-talents.txt are class-wide (spec_id null); hero-*.txt trees
     * aren't attributable to a single spec from this data (spec_id null, still correctly
     * class-attributed); anything else is matched against the class's spec names.
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

                $key = $source.'|'.($specId ?? 'null');
                $availabilityBySpellId[$record['spell_id']][$key] = ['source' => $source, 'spec_id' => $specId];
            }
        }

        foreach ($records as $record) {
            $spell = $this->upsertTrack(Spell::class, [
                'patch_id' => $patch->id,
                'spell_id' => $record['spell_id'],
            ], [
                'name' => $record['name'],
                'school' => $record['school'],
                'description' => $record['description'],
            ], 'spells');

            $this->spellIndex[$record['spell_id']] = $spell;

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
     */
    private function importTalentTrees(GameClass $class, Patch $patch, array $classSpecs, array $treeJson): void
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
            $tree = $this->upsertTrack(TalentTree::class, [
                'patch_id' => $patch->id,
                'class_id' => $class->id,
                'type' => 'hero',
                'name' => $heroData['name'] ?? ('Hero Tree #'.($heroData['id'] ?? '?')),
            ], [
                'spec_id' => null,
                'external_tree_id' => $heroData['id'] ?? null,
            ], 'talent_trees');
            $this->importTreeNodes($tree, $patch->id, $heroData['nodes'] ?? []);
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
    }
}
