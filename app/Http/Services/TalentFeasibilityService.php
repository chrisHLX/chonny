<?php

namespace App\Http\Services;

use App\Models\Specialization;
use App\Models\TalentTree;
use Illuminate\Support\Facades\DB;

/**
 * Can one character actually press all of these abilities?
 *
 * WHY THIS EXISTS. The first real reader of the machine-drafted guides said the same thing about
 * several of them, and said it most sharply about WLS vs RMP: "the idea is correct, the spells
 * available is not". A plan that opens with Shadowfury and follows with Howl of Terror is not a
 * plan — those two are entries on the SAME choice node (warlock class tree node 3473, type
 * CHOICE), so no warlock has ever had both. Nothing in the draft pipeline noticed, because a
 * draft only ever asserted that an ability exists, never that a single build could hold it.
 *
 * So this answers the narrower, checkable question: given a spec and a set of ability names, which
 * are talent-gated at all, and which pairs are mutually exclusive? It deliberately does NOT try to
 * answer "is this a legal 71-point build" — point totals, gate rows and edge prerequisites are a
 * different and much larger problem, and a guide that names eight abilities is not claiming a full
 * build anyway. Choice-node exclusivity is the one constraint that makes a plan simply impossible
 * rather than merely expensive, and it is the one the reader actually caught.
 *
 * MATCHED BY NAME, NOT BY ID, and that is deliberate. `talent_node_entries.spell_id` is an FK to
 * `spells.id` — the internal auto-increment key — despite the column name, while guide blocks
 * store Blizzard's external `spell_id`. One visible ability is also frequently several internal
 * copies, and the copy a talent entry points at is routinely not the pressable copy a guide step
 * resolves to. Comparing either id space directly gives clean, confident, wrong answers. The
 * display name is the thing that is stable across both.
 */
class TalentFeasibilityService
{
    /**
     * Every talent-tree entry this spec can reach, keyed by ability display name.
     *
     * @return array<string, array<int, array{node: int, type: string, tree: string}>>
     */
    public function entriesFor(Specialization $spec): array
    {
        $treeIds = $this->treeIdsFor($spec);

        if ($treeIds === []) {
            return [];
        }

        $rows = DB::table('talent_node_entries as e')
            ->join('talent_nodes as n', 'n.id', '=', 'e.talent_node_id')
            ->join('talent_trees as t', 't.id', '=', 'n.talent_tree_id')
            ->join('spells as s', 's.id', '=', 'e.spell_id')
            ->whereIn('t.id', $treeIds)
            ->select('s.name', 'n.id as node_id', 'n.type as node_type', 't.name as tree_name')
            ->get();

        $byName = [];

        foreach ($rows as $row) {
            // Strip the "(desc=...)" disambiguator the importer appends to same-named copies, so a
            // draft that says "Shadowfury" matches whichever copy the tree happens to point at.
            $name = trim(preg_replace('/\s*\(desc=.*$/', '', $row->name));

            $byName[$name][$row->node_id] = [
                'node' => $row->node_id,
                'type' => $row->node_type,
                'tree' => $row->tree_name,
            ];
        }

        return array_map('array_values', $byName);
    }

    /**
     * Check a set of ability names against one spec.
     *
     * @param  array<int, string>  $names
     * @return array{
     *     gated: array<string, array<int, array{node: int, type: string, tree: string}>>,
     *     baseline: array<int, string>,
     *     conflicts: array<int, array{node: int, tree: string, abilities: array<int, string>}>
     * }
     */
    public function check(Specialization $spec, array $names): array
    {
        $entries = $this->entriesFor($spec);
        $names = array_values(array_unique($names));

        $gated = [];
        $baseline = [];

        foreach ($names as $name) {
            if (isset($entries[$name])) {
                $gated[$name] = $entries[$name];
            } else {
                $baseline[] = $name;
            }
        }

        // A conflict is two DIFFERENT abilities sharing one CHOICE node. Same ability appearing
        // twice in a plan is not a conflict — a guide may well press Cheap Shot more than once.
        $byNode = [];

        foreach ($gated as $name => $nodes) {
            foreach ($nodes as $node) {
                if ($node['type'] !== 'CHOICE') {
                    continue;
                }

                $byNode[$node['node']]['tree'] = $node['tree'];
                $byNode[$node['node']]['abilities'][$name] = true;
            }
        }

        $conflicts = [];

        foreach ($byNode as $nodeId => $group) {
            if (count($group['abilities']) < 2) {
                continue;
            }

            $conflicts[] = [
                'node' => $nodeId,
                'tree' => $group['tree'],
                'abilities' => array_keys($group['abilities']),
            ];
        }

        return ['gated' => $gated, 'baseline' => $baseline, 'conflicts' => $conflicts];
    }

    /**
     * The three tiers a spec draws talents from: its class tree (class-wide, `spec_id` NULL), its
     * own spec tree, and the hero trees it is eligible for. Hero trees are class-wide rows joined
     * through `talent_tree_specializations` and are NOT found by `spec_id` — see the pivot's
     * docblock and Specialization::heroTalentTrees().
     *
     * @return array<int, int>
     */
    private function treeIdsFor(Specialization $spec): array
    {
        $class = TalentTree::query()
            ->where('class_id', $spec->class_id)
            ->whereNull('spec_id')
            ->pluck('id');

        $own = TalentTree::query()
            ->where('spec_id', $spec->id)
            ->pluck('id');

        $hero = $spec->heroTalentTrees()->pluck('talent_trees.id');

        return $class->merge($own)->merge($hero)->unique()->values()->all();
    }
}
