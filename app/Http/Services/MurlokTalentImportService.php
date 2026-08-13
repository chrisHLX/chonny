<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use App\Models\TalentBuildPvpChoice;
use App\Models\TalentNode;
use App\Models\TalentTree;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Populates a spec's admin-curated default TalentBuild (see TalentSelectionService::
 * getOrCreateDefaultBuild()) from murlok.io's public per-spec/bracket guide page
 * (e.g. https://murlok.io/death-knight/unholy/3v3) instead of requiring an admin to hand-pick
 * every talent one at a time in TalentBuildEditor.
 *
 * This deliberately does NOT use murlok's per-character "Copy talents" export string — that
 * string is generated client-side by a WebAssembly module (wasm_exec.js) with nothing in the
 * HTML response to read, so a plain HTTP fetch can't retrieve it. It also does not go through
 * Blizzard's own Character Specializations API — that endpoint's `loadouts` field (a character's
 * selected talents) has been confirmed broken/missing since patch 11.2, which is the actual
 * reason murlok itself needs a player-run addon for character-specific data at all.
 *
 * What IS scrapable, and what this class reads: murlok's per-spec/bracket GUIDE page (not a
 * character page) is plain server-rendered HTML — a heatmap of pick counts (0-50, "top 50
 * players in this spec/bracket") per talent node, plus a labeled hero-tree section and a flat
 * PvP-talents section. No WASM involved. Verified against a real fetch (Unholy DK, 3v3) before
 * this class was written.
 *
 * Resolution strategy: rather than trying to infer murlok's own choice-node pairing or grid
 * layout from its markup, this reads OUR OWN talent_nodes/talent_node_entries structure (already
 * correctly imported from Blizzard's Game Data API — see game-data.md) and asks, for each of our
 * own nodes, "what did murlok say the pick count was for each of this node's possible spells?" —
 * matched purely by spell name. This sidesteps needing to parse murlok's grid-position/choice
 * pairing at all. A name that can't be matched is logged, never guessed at.
 */
class MurlokTalentImportService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * @return array{
     *   specId: int, bracket: string, url: string,
     *   heroTreeName: ?string,
     *   classNodesTotal: int, classNodesSelected: int,
     *   specNodesTotal: int, specNodesSelected: int,
     *   heroNodesTotal: int, heroNodesSelected: int,
     *   pvpTalentsSelected: array<int, string>,
     *   unmatchedNames: array<int, string>,
     *   choices: Collection<int, array{node: TalentNode, entry: \App\Models\TalentNodeEntry}>,
     *   pvpTalentIds: array<int, int>,
     * }
     */
    public function preview(Specialization $spec, string $bracket = '3v3'): array
    {
        $patchId = $this->currentPatchIdForSpec($spec);

        if (!$patchId) {
            throw new RuntimeException("No current patch found for spec '{$spec->name}'.");
        }

        $classSlug = Str::slug($spec->gameClass->name);
        $specSlug = Str::slug($spec->name);
        $url = "https://murlok.io/{$classSlug}/{$specSlug}/{$bracket}";

        $html = $this->fetch($url);
        $parsed = $this->parse($html);

        $unmatched = [];

        $classTree = TalentTree::where('class_id', $spec->class_id)->where('patch_id', $patchId)->where('type', 'class')->with('nodes.entries.spell')->first();
        $specTree = TalentTree::where('spec_id', $spec->id)->where('patch_id', $patchId)->where('type', 'spec')->with('nodes.entries.spell')->first();

        if ($classTree) {
            $this->stripBloatedClassNodes($classTree, $spec->class_id, $patchId);
        }

        // The page renders every hero tree available to the spec, not just the meta one (see
        // parseHeroBlocks()'s docblock) — pick whichever block has the highest total pick count
        // across its own entries, since that's the one real top players are actually using.
        $metaHeroBlock = collect($parsed['hero'])->sortByDesc(fn ($block) => collect($block['entries'])->sum('count'))->first();
        $heroTree = $metaHeroBlock ? $this->resolveHeroTree($spec, $patchId, $metaHeroBlock['treeName'], $unmatched) : null;

        $classResolution = $classTree ? $this->resolveTreeChoices($classTree, $parsed['class'], $unmatched) : ['choices' => collect(), 'total' => 0];
        $specResolution = $specTree ? $this->resolveTreeChoices($specTree, $parsed['spec'], $unmatched) : ['choices' => collect(), 'total' => 0];
        $heroResolution = $heroTree ? $this->resolveTreeChoices($heroTree, $metaHeroBlock['entries'], $unmatched) : ['choices' => collect(), 'total' => 0];

        $pvpResolution = $this->resolvePvpTalents($spec, $patchId, $parsed['pvp'], $unmatched);

        $choices = $classResolution['choices']->concat($specResolution['choices'])->concat($heroResolution['choices']);

        return [
            'specId' => $spec->id,
            'patchId' => $patchId,
            'bracket' => $bracket,
            'url' => $url,
            'heroTreeName' => $heroTree?->name,
            'classNodesTotal' => $classResolution['total'],
            'classNodesSelected' => $classResolution['choices']->count(),
            'specNodesTotal' => $specResolution['total'],
            'specNodesSelected' => $specResolution['choices']->count(),
            'heroNodesTotal' => $heroResolution['total'],
            'heroNodesSelected' => $heroResolution['choices']->count(),
            'pvpTalentsSelected' => $pvpResolution['names'],
            'unmatchedNames' => array_values(array_unique($unmatched)),
            'choices' => $choices,
            'pvpTalentIds' => $pvpResolution['ids'],
        ];
    }

    /**
     * Writes the preview's resolved selections onto the spec's admin default TalentBuild,
     * replacing (not appending to) any choices it already had — same "replace not append"
     * precedent as RoadmapService::persistStagesForUser() and TalentSelectionService::
     * syncPvpChoices(). Call preview() first and only pass its result here once it looks right —
     * mirrors TalentSelector's own preview-before-apply import flow.
     *
     * @param  array  $preview  the exact return value of preview()
     */
    public function apply(array $preview, TalentSelectionService $talentService): TalentBuild
    {
        $build = $talentService->getOrCreateDefaultBuild($preview['specId'], $preview['patchId']);

        $build->choices()->delete();

        foreach ($preview['choices'] as $choice) {
            TalentBuildChoice::create([
                'talent_build_id' => $build->id,
                'talent_node_id' => $choice['node']->id,
                'chosen_entry_id' => $choice['entry']->id,
                'rank' => $choice['entry']->rank,
            ]);
        }

        $talentService->syncPvpChoices($build, $preview['pvpTalentIds']);

        return $build->fresh(['choices', 'pvpChoices']);
    }

    private function fetch(string $url): string
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(15)
            ->retry(2, 1000)
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch {$url} — HTTP {$response->status()}. Check the class/spec slugs and bracket are correct for murlok's URL scheme.");
        }

        return $response->body();
    }

    /**
     * @return array{
     *   class: array<int, array{name: string, count: int}>,
     *   spec: array<int, array{name: string, count: int}>,
     *   hero: array{treeName: ?string, entries: array<int, array{name: string, count: int}>},
     *   pvp: array<int, array{name: string, count: int}>,
     * }
     */
    private function parse(string $html): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        return [
            'class' => $this->parseSection($xpath, 'talents-class'),
            'spec' => $this->parseSection($xpath, 'talents-specialization'),
            'hero' => $this->parseHeroBlocks($xpath),
            'pvp' => $this->parseSection($xpath, 'talents-pvp'),
        ];
    }

    /**
     * murlok's guide page renders EVERY hero tree available to the spec under #talents-hero, not
     * just the meta one — confirmed against a real fetch (Unholy DK 3v3 shows both San'layn, all
     * 0 picks in that bracket, AND Rider of the Apocalypse, all 50 picks, as two separate
     * `div.hero` blocks each with their own <h3>). Picking just the first heading (an earlier,
     * wrong version of this method) would have silently resolved to whichever tree happens to be
     * rendered first, regardless of which one real players actually use. This returns every
     * block found; the caller in preview() picks the one with the highest aggregate pick count.
     *
     * @return array<int, array{treeName: string, entries: array<int, array{name: string, count: int}>}>
     */
    private function parseHeroBlocks(DOMXPath $xpath): array
    {
        $blocks = $xpath->query('//*[@id="talents-hero"]//div[contains(concat(" ", normalize-space(@class), " "), " hero ")]');
        $result = [];

        foreach ($blocks as $block) {
            $heading = $xpath->query('.//h3', $block)->item(0);

            if (!$heading) {
                continue;
            }

            $treeName = trim(preg_replace('/\s*Hero Talents\s*$/i', '', $heading->textContent));
            $result[] = ['treeName' => $treeName, 'entries' => $this->parseCells($xpath, $block)];
        }

        return $result;
    }

    /** @return array<int, array{name: string, count: int}> */
    private function parseSection(DOMXPath $xpath, string $containerId): array
    {
        $container = $xpath->query('//*[@id="'.$containerId.'"]')->item(0);

        return $container ? $this->parseCells($xpath, $container) : [];
    }

    /** @return array<int, array{name: string, count: int}> */
    private function parseCells(DOMXPath $xpath, \DOMNode $context): array
    {
        $cells = $xpath->query('.//li[contains(concat(" ", normalize-space(@class), " "), " guide-talent-tree-cell ")]', $context);
        $entries = [];

        foreach ($cells as $cell) {
            $img = $xpath->query('.//img[@alt]', $cell)->item(0);
            $countNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " guide-talent-count ")]', $cell)->item(0);

            if (!$img || !$countNode) {
                continue;
            }

            $entries[] = [
                'name' => trim($img->getAttribute('alt')),
                'count' => (int) trim($countNode->textContent),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, array{name: string, count: int}>  $parsedEntries
     * Strips the class-tree bloat documented in TalentSelector::getClassTalentNodesProperty()
     * (CLAUDE.md's "class-tree bloat" note) — Blizzard's bare class-tree endpoint has, at various
     * points, echoed nearly every one of the class's own spec-tree nodes back into the class
     * tree's own node list, and because talent_nodes upserts are additive-only, an import done
     * while that bug was live leaves those duplicate rows in the DB permanently, even after a
     * later clean re-fetch. Without this filter, resolveTreeChoices() below would name-match
     * murlok's page text against both the real node AND its bloated class-tree duplicate,
     * writing two talent_build_choices rows for one real talent — confirmed happening in
     * practice (2026-08-13) across every spec whose class tree carried this duplication.
     * Mirrors TalentSelector's own filter exactly (same comparison: any class-tree node whose
     * external_node_id also appears in ANY of this class's spec/hero trees is dropped) so both
     * surfaces agree on what the "real" class tree looks like. Mutates $classTree's already
     *-loaded 'nodes' relation in place via setRelation() rather than re-querying.
     */
    private function stripBloatedClassNodes(TalentTree $classTree, int $classId, int $patchId): void
    {
        $specHeroExternalIds = TalentNode::whereHas(
            'talentTree',
            fn ($q) => $q->where('class_id', $classId)
                ->where('patch_id', $patchId)
                ->whereIn('type', ['spec', 'hero'])
        )->pluck('external_node_id');

        $classTree->setRelation(
            'nodes',
            $classTree->nodes->reject(fn (TalentNode $n) => $specHeroExternalIds->contains($n->external_node_id))->values()
        );
    }

    /**
     * @param  array<int, string>  &$unmatched
     * @return array{choices: Collection, total: int}
     */
    private function resolveTreeChoices(TalentTree $tree, array $parsedEntries, array &$unmatched): array
    {
        $countByName = collect($parsedEntries)->groupBy(fn ($e) => $this->normalizeSpellName($e['name']))->map(fn ($g) => (int) $g->max('count'));

        $choices = collect();

        foreach ($tree->nodes as $node) {
            $entries = $node->entries;

            if ($entries->isEmpty()) {
                continue;
            }

            $best = null;
            $bestCount = 0;

            foreach ($entries as $entry) {
                $key = $this->normalizeSpellName($entry->spell->name ?? '');

                if (!$countByName->has($key)) {
                    $unmatched[] = $entry->spell->name ?? "spell_id {$entry->spell_id}";

                    continue;
                }

                $count = $countByName->get($key);

                // Prefer higher pick count; among same-name ties (a leveled single node stored
                // as one entry per rank tier) prefer the higher rank.
                $isBetter = $best === null
                    || $count > $bestCount
                    || ($count === $bestCount && $entry->rank > $best->rank);

                if ($isBetter) {
                    $best = $entry;
                    $bestCount = $count;
                }
            }

            if ($best !== null && $bestCount > 0) {
                $choices->push(['node' => $node, 'entry' => $best]);
            }
        }

        return ['choices' => $choices, 'total' => $tree->nodes->count()];
    }

    /**
     * @param  array<int, array{name: string, count: int}>  $parsedEntries
     * @param  array<int, string>  &$unmatched
     * @return array{ids: array<int, int>, names: array<int, string>}
     */
    private function resolvePvpTalents(Specialization $spec, int $patchId, array $parsedEntries, array &$unmatched): array
    {
        $countByName = collect($parsedEntries)->groupBy(fn ($e) => $this->normalizeSpellName($e['name']))->map(fn ($g) => (int) $g->max('count'));

        $pvpTalents = PvpTalent::where('spec_id', $spec->id)->where('patch_id', $patchId)->with('spell')->get();

        $ranked = collect();

        foreach ($pvpTalents as $talent) {
            $key = $this->normalizeSpellName($talent->spell->name ?? '');

            if (!$countByName->has($key)) {
                $unmatched[] = $talent->spell->name ?? "spell_id {$talent->spell_id}";

                continue;
            }

            $count = $countByName->get($key);

            if ($count > 0) {
                $ranked->push(['talent' => $talent, 'count' => $count]);
            }
        }

        // murlok's own PvP talent UI is a fixed 4 flat slots (no per-slot restriction in our data
        // either — see TalentSelectionService::syncPvpChoices()'s docblock) — top 4 by pick count.
        $top = $ranked->sortByDesc('count')->take(4);

        return [
            'ids' => $top->pluck('talent.id')->all(),
            'names' => $top->map(fn ($r) => $r['talent']->spell->name.' ('.$r['count'].'/50)')->all(),
        ];
    }

    /**
     * Matches murlok's labeled hero-tree section (e.g. "San'layn Hero Talents") against the
     * spec's actual available hero trees by name. Logged as unmatched (not guessed/defaulted to
     * the first available tree) if the name doesn't line up — a real possibility if Blizzard
     * renamed a hero tree and murlok or our own import haven't both caught up yet.
     */
    private function resolveHeroTree(Specialization $spec, int $patchId, ?string $parsedName, array &$unmatched): ?TalentTree
    {
        if (!$parsedName) {
            return null;
        }

        $available = TalentTree::where('type', 'hero')
            ->where('patch_id', $patchId)
            ->whereHas('specializations', fn ($q) => $q->where('specializations.id', $spec->id))
            ->with('nodes.entries.spell')
            ->get();

        $match = $available->first(fn ($t) => Str::lower(trim($t->name)) === Str::lower(trim($parsedName)));

        if (!$match) {
            $unmatched[] = "Hero tree '{$parsedName}' (page) did not match any of: ".$available->pluck('name')->implode(', ');
        }

        return $match;
    }

    /**
     * Strips the "(desc=Color)" disambiguation suffix (same annotation documented at length in
     * TalentSelectionService — see its "(desc=...)" note) before comparing a spell name against
     * murlok's page text. Found 2026-08-09 investigating a real report ("hardly any spells for
     * Evoker"): Evoker's core kit routinely carries this suffix as a legitimate part of its raw
     * name (e.g. "Pyre (desc=Red)", "Dragonrage (desc=Red)", "Eternity Surge (desc=Blue)") — a
     * per-dragonflight-color naming convention specific to this class, not the "noise" the same
     * suffix represents for other classes (pet-family/artifact/covenant duplicates). murlok's
     * page never shows this internal annotation to players, so every one of these spells failed
     * to match and landed in `unmatched` instead of being selected — Pyre and Dragonrage, two of
     * Devastation's most-picked talents, were silently skipped this way. Without this
     * normalization, murlok import is effectively broken for Evoker specifically, even though it
     * worked correctly for Unholy DK (whose kit has no `(desc=...)`-suffixed real abilities).
     */
    private function normalizeSpellName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s*\(desc=[^)]*\)\s*$/i', '', trim($name))));
    }

    private function currentPatchIdForSpec(Specialization $spec): ?int
    {
        $gameId = $spec->game()?->id;

        if (!$gameId) {
            return null;
        }

        return Patch::where('game_id', $gameId)->where('is_current', true)->value('id');
    }
}
