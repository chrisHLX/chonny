<?php

namespace App\Http\Services;

use App\Models\BattlenetCharacter;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\TalentNode;
use App\Models\TalentTree;
use Illuminate\Support\Collection;

/**
 * Resolves a synced character's talent snapshot (Blizzard's external ids — see
 * BattlenetCharacterSyncService::parseTalents()) onto the CURRENT patch's talent tables, in the
 * exact shape the read-only <livewire:talent-selector> takes.
 *
 * Resolution happens at render time rather than at sync time for the same reason guide blocks store
 * external spell ids: this database's talent rows are patch-scoped and reassigned on a patch bump,
 * so an internal id written today would silently point at nothing (or something else) next patch.
 *
 * SCOPED BY THE TREE BLIZZARD SAYS THE PICK CAME FROM. `external_node_id` is only unique within a
 * tree, and two known import artefacts put copies of the same node in more than one of a spec's
 * trees — hero nodes duplicated into spec trees (1,127 of them, see CLAUDE.md's talent-grid notes)
 * and the same id appearing across a class's trees. Each pick carries its own tree (class | spec |
 * hero), so it is matched only against that tree, and a hero pick only against the hero tree the
 * character actually has selected.
 *
 * Anything that cannot be matched is RETURNED BY NAME, never dropped and never guessed — a live
 * build on a newer patch than this database will have talents we do not know yet, and the page
 * says so instead of quietly showing a shorter build.
 */
class CharacterTalentResolver
{
    /**
     * The character's build for one spec (its active spec when none is named), resolved — or null
     * when there is no snapshot for it.
     */
    public function forCharacter(BattlenetCharacter $character, ?int $specExternalId = null): ?array
    {
        $snapshots = collect($character->talents ?? []);

        $snapshot = $specExternalId
            ? $snapshots->firstWhere('spec_external_id', $specExternalId)
            : $character->activeTalents();

        return $snapshot ? $this->resolve($snapshot) + ['heroTree' => $snapshot['hero_tree'] ?? null] : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot  one entry of BattlenetCharacter::$talents
     * @return array{
     *   spec: ?Specialization,
     *   chosenEntries: array<int, int>,
     *   pvpTalentIds: list<int>,
     *   unresolved: list<string>,
     *   pvpUnresolved: list<string>,
     *   resolvedCount: int,
     *   totalCount: int
     * }
     */
    public function resolve(array $snapshot): array
    {
        $empty = [
            'spec' => null, 'chosenEntries' => [], 'pvpTalentIds' => [], 'unresolved' => [],
            'pvpUnresolved' => [], 'resolvedCount' => 0, 'totalCount' => count($snapshot['picks'] ?? []),
        ];

        $spec = Specialization::where('external_spec_id', $snapshot['spec_external_id'] ?? 0)->first();
        $patchId = Patch::where('is_current', true)->value('id');

        if (! $spec || ! $patchId) {
            $empty['unresolved'] = collect($snapshot['picks'] ?? [])->pluck('name')->filter()->values()->all();

            return $empty;
        }

        $nodesByTree = $this->nodesByTree($spec, $patchId, $snapshot['hero_tree'] ?? null);

        $chosen = [];
        $unresolved = [];

        foreach ($snapshot['picks'] ?? [] as $pick) {
            $node = $nodesByTree[$pick['tree'] ?? 'spec']->get($pick['node'] ?? 0);

            // The hero-tree SELECTOR node: it exists in the tree, has no entries here or in
            // Blizzard's response (no tooltip, no name), and is how the game records which hero
            // tree was chosen. Structural, not a talent — reporting it as "unresolved" would be a
            // false alarm on every character (confirmed: node 99820 on a real Unholy DK).
            if ($node && $node->entries->isEmpty() && empty($pick['name'])) {
                continue;
            }

            $entry = $node ? $this->entryFor($node, $pick) : null;

            if ($entry) {
                // Keyed by node, so a node listed more than once collapses to one: Midnight's
                // progressive nodes (e.g. Forbidden Knowledge, three entries on one node) arrive
                // as one pick per stage, in order, and the calculator holds one entry per node —
                // the last listed is the furthest stage reached.
                $chosen[$node->id] = $entry->id;
            } else {
                $unresolved[] = $pick['name'] ?? "Talent node #{$pick['node']}";
            }
        }

        [$pvpIds, $pvpUnresolved] = $this->resolvePvp($snapshot['pvp'] ?? [], $spec, $patchId);

        return [
            'spec' => $spec,
            'chosenEntries' => $chosen,
            'pvpTalentIds' => $pvpIds,
            'unresolved' => $unresolved,
            'pvpUnresolved' => $pvpUnresolved,
            'resolvedCount' => count($chosen),
            'totalCount' => count($chosen) + count($unresolved),
        ];
    }

    /** @return array{class: Collection, spec: Collection, hero: Collection} keyed by external_node_id */
    private function nodesByTree(Specialization $spec, int $patchId, ?string $heroTreeName): array
    {
        $trees = TalentTree::where('patch_id', $patchId)
            ->where(function ($q) use ($spec) {
                $q->where(fn ($q2) => $q2->where('class_id', $spec->class_id)->where('type', 'class'))
                    ->orWhere(fn ($q2) => $q2->where('spec_id', $spec->id)->where('type', 'spec'))
                    ->orWhere(fn ($q2) => $q2->where('type', 'hero')
                        ->whereHas('specializations', fn ($q3) => $q3->where('specializations.id', $spec->id)));
            })
            ->get();

        // Only the hero tree actually selected — both of a spec's hero trees can hold nodes with
        // the same external id. With no name to go on, every hero tree the spec can reach.
        $heroTrees = $trees->where('type', 'hero');
        if ($heroTreeName && $heroTrees->contains('name', $heroTreeName)) {
            $heroTrees = $heroTrees->where('name', $heroTreeName);
        }

        $load = fn (Collection $ts) => TalentNode::whereIn('talent_tree_id', $ts->pluck('id'))
            ->with('entries.spell')
            ->get()
            ->keyBy('external_node_id');

        return [
            'class' => $load($trees->where('type', 'class')),
            'spec' => $load($trees->where('type', 'spec')),
            'hero' => $load($heroTrees),
        ];
    }

    /**
     * The entry a pick means, most specific evidence first: Blizzard's talent id at the picked
     * rank, then the same talent id at any rank, then the spell id, and only for a node with a
     * single distinct spell (so there is no choice to get wrong) the entry at that rank.
     */
    private function entryFor(TalentNode $node, array $pick)
    {
        $rank = (int) ($pick['rank'] ?? 1);
        $entries = $node->entries;

        $atRank = fn (Collection $es) => $es->firstWhere('rank', $rank)
            ?? $es->where('rank', '<=', $rank)->sortByDesc('rank')->first()
            ?? $es->sortBy('rank')->first();

        if (! empty($pick['talent'])) {
            $byTalent = $entries->where('external_talent_id', (int) $pick['talent']);
            if ($byTalent->isNotEmpty()) {
                return $atRank($byTalent);
            }
        }

        if (! empty($pick['spell'])) {
            $bySpell = $entries->filter(fn ($e) => $e->spell && (int) $e->spell->spell_id === (int) $pick['spell']);
            if ($bySpell->isNotEmpty()) {
                return $atRank($bySpell);
            }
        }

        if ($entries->isNotEmpty() && $entries->pluck('spell_id')->unique()->count() === 1) {
            return $atRank($entries);
        }

        return null;
    }

    /** @return array{0: list<int>, 1: list<string>} */
    private function resolvePvp(array $pvp, Specialization $spec, int $patchId): array
    {
        if ($pvp === []) {
            return [[], []];
        }

        $rows = PvpTalent::where('spec_id', $spec->id)->where('patch_id', $patchId)->with('spell')->get();
        $ids = [];
        $missing = [];

        foreach ($pvp as $p) {
            $row = $rows->firstWhere('external_pvp_talent_id', (int) ($p['pvp_talent'] ?? 0))
                ?? (! empty($p['spell']) ? $rows->first(fn ($r) => (int) $r->spell?->spell_id === (int) $p['spell']) : null);

            if ($row) {
                $ids[] = $row->id;
            } else {
                $missing[] = $p['name'] ?? 'Unknown PvP talent';
            }
        }

        return [array_values(array_unique($ids)), $missing];
    }
}
