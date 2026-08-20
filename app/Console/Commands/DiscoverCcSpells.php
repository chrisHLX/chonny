<?php

namespace App\Console\Commands;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Scans every raw arena log on file for real cast/aura evidence of spells that currently have
 * no `dr_category` (so don't appear on the Synergies tab at all) but carry a Blizzard `mechanic`
 * tag suggesting they're CC. Closes the one described-but-missing capability from
 * spell-acquisition-model.md — wow:diff-arena-spells/wow:discover-spec-spells answer "which spec
 * can cast this spell," never "is this cast spell CC-shaped and untagged."
 *
 * TRUST MODEL — deliberately built to trust real arena-log evidence directly, not gated behind
 * per-line manual review the way this project's *reverted* heuristics would have needed (Mind
 * Sear's leak via spells.mechanic/spell_relationships, the abandoned alwaysAvailableAbilityIds()).
 * Those failed because the underlying SIGNAL was unreliable, not because real observed match data
 * can't be trusted — a spell a real, spec-identified player actually cast in a real match is
 * direct evidence it's real and used, the same tier wow:diff-arena-spells --apply already writes
 * on without a second manual pass. See CLAUDE.md's "Spell Acquisition Model" section for the full
 * reasoning.
 *
 * The genuinely open question was never "can we trust arena logs" — it's "does spells.mechanic
 * reliably say WHICH dr_category bucket." Answered empirically before writing any mapping here,
 * not guessed: queried every spell in the current patch that already has BOTH mechanic and
 * dr_category hand-curated (71 spells) and grouped by mechanic. Most map to exactly one
 * dr_category with zero exceptions (Stun->Stun 7/7, Root->Root 2/2, Flee->Disorient 4/4,
 * Polymorph/Sleep/Sap/Shackle/Charm->Incapacitate or Disorient depending, each internally
 * consistent, Snare->Slow 38/39 with the one exception — Charge — already correctly hand-curated
 * as Root separately and therefore never reachable as a "new" candidate here). Two mechanics came
 * back genuinely mixed even in real curated data: Banish (Incapacitate for the ability literally
 * named "Banish," Disorient for Cyclone) and Bleed (incidental to Rake/Garrote's real CC
 * component, not predictive of it at all). Those two, and any mechanic with zero empirical
 * precedent yet, are always reported, never auto-applied — the mapping tier below is the line
 * between "confirmed, write it" and "flag it, a human decides."
 *
 * `--apply` (same shape as wow:diff-arena-spells' own flag) writes ONLY the HIGH-confidence tier
 * to data/spelldata/cc-synergies-overrides.txt and re-imports immediately. NEEDS-REVIEW
 * candidates are always printed, never written, regardless of --apply.
 *
 * Usage:
 *   php artisan wow:discover-cc-spells
 *   php artisan wow:discover-cc-spells --apply
 */
class DiscoverCcSpells extends Command
{
    protected $signature = 'wow:discover-cc-spells {--apply : Write the HIGH-confidence tier to cc-synergies-overrides.txt and re-import immediately}';

    protected $description = 'Find spells with no dr_category but real arena-log cast evidence and a CC-shaped mechanic tag; --apply writes only the unambiguous mechanic mappings';

    /**
     * Built from a real empirical query (see class docblock) against every spell in the current
     * patch already carrying both `mechanic` and a hand-curated `dr_category` — not guessed.
     * Only mechanics with 100% (or effectively 100%, single already-handled exception) agreement
     * across every real curated example are here.
     */
    private const HIGH_CONFIDENCE_MAP = [
        'Stun' => 'Stun',
        'Root' => 'Root',
        'Silence' => 'Silence',
        'Disorient' => 'Disorient',
        'Incapacitate' => 'Incapacitate',
        'Polymorph' => 'Incapacitate',
        'Sap' => 'Incapacitate',
        'Shackle' => 'Incapacitate',
        'Charm' => 'Disorient',
        'Flee' => 'Disorient',
        'Turn' => 'Disorient',
        'Snare' => 'Slow',
    ];

    /**
     * Mechanics that are CC-adjacent (per ModuleSpellReferenceService::MECHANIC_CATEGORY_MAP's
     * own 'Crowd Control' bucket) but confirmed genuinely mixed against real curated data
     * (Banish: Incapacitate for the ability literally named Banish, Disorient for Cyclone;
     * Bleed: incidental to Rake/Garrote's real CC component, not predictive at all), or with
     * zero empirical precedent either way (Freeze, Horrify). 'Sleep' moved here 2026-08-17 after
     * a real, direct textual contradiction was caught before shipping: Hibernate (mechanic=Sleep)
     * is a genuine Incapacitate ("preventing all actions"), but Sleep Walk (also mechanic=Sleep)
     * reads "Disorient an enemy... causing them to sleep walk" in its OWN description — the same
     * shape of split as Banish, just not visible until a second real example existed. Always
     * surfaced as candidates worth a human look, never mapped to a dr_category automatically.
     */
    private const NEEDS_REVIEW_MECHANICS = ['Banish', 'Bleed', 'Freeze', 'Horrify', 'Sleep'];

    /**
     * Spell names this project has already investigated and explicitly declined to tag, for
     * reasons unrelated to arena-log evidence — re-discovering them here would silently
     * relitigate a settled decision. Sourced from CLAUDE.md's "Slow category added" section:
     * these carry mechanic='Snare' but their own description text has nothing to do with
     * movement speed at all (confirmed spurious Blizzard-side tags, not a real Slow effect) —
     * Fatal Flourish and Divine Hammer were caught here by spot-checking this command's own
     * first real run (their text is pure damage/proc, zero mention of slowing), Frozen Orb and
     * Searing Dialogue carried over from that same prior investigation even though they didn't
     * happen to surface in this particular arena-log sample. Numbing Poison is a related but
     * distinct case — real effect, but reduces CASTING speed, not movement speed, which this
     * project's Slow category is specifically about; left deliberately undecided rather than
     * silently included.
     */
    private const KNOWN_EXCLUDED_NAMES = ['Fatal Flourish', 'Frozen Orb', 'Divine Hammer', 'Searing Dialogue', 'Numbing Poison'];

    public function handle(): int
    {
        $patch = Patch::where('is_current', true)->first();

        if (!$patch) {
            $this->error('No current patch found.');

            return self::FAILURE;
        }

        $allMechanics = array_merge(array_keys(self::HIGH_CONFIDENCE_MAP), self::NEEDS_REVIEW_MECHANICS);

        $candidates = Spell::where('patch_id', $patch->id)
            ->whereNull('dr_category')
            ->where('is_passive', false)
            ->whereIn('mechanic', $allMechanics)
            ->get(['id', 'spell_id', 'name', 'mechanic']);

        $this->info("{$candidates->count()} untagged, non-passive spell(s) with a CC-adjacent mechanic tag — checking real arena-log cast evidence for each...");

        if ($candidates->isEmpty()) {
            return self::SUCCESS;
        }

        $files = glob(config('arena_logs.archive_path').'/raw/*.log.gz');

        if ($files === []) {
            $this->error('No arena logs on file — run wow:fetch-arena-log first.');

            return self::FAILURE;
        }

        // spell_id => count of matches it was seen cast in.
        $seenInMatches = array_fill_keys($candidates->pluck('spell_id')->all(), 0);

        foreach ($files as $file) {
            $raw = gzdecode(File::get($file));

            foreach ($seenInMatches as $spellId => $count) {
                if (str_contains($raw, ",{$spellId},\"")) {
                    $seenInMatches[$spellId]++;
                }
            }
        }

        // Same-DISPLAY-named-duplicate guard — the single most-documented failure mode in this
        // dataset (Intimidation/Fear/Blind/Storm Bolt/etc. each have an internal duplicate
        // spell_id with no cooldown data of its own, sharing a name — or a "(desc=...)"-suffixed
        // variant of a name — with the real, already-correctly-curated copy). Built against
        // Spell::getDisplayNameAttribute() (the same "(desc=...)" suffix-stripping this project
        // already uses everywhere else a duplicate copy needs recognizing as the same underlying
        // ability), not the raw `name` column — a raw-name-only version of this guard was tried
        // first and still let "Lightning Lasso (desc=PvP Talent)" (spell_id 305485, no cooldown
        // data of its own) through, since it doesn't share an EXACT string with the real,
        // already-tagged "Lightning Lasso" (305483) despite being the same ability. Confirmed
        // empirically before the raw-name version even shipped: 14 of an initial 32 raw
        // candidates were exactly this pattern (see CLAUDE.md).
        $alreadyTaggedByDisplayName = Spell::where('patch_id', $patch->id)
            ->whereNotNull('dr_category')
            ->get(['spell_id', 'name', 'dr_category'])
            ->keyBy(fn (Spell $s) => $s->display_name);

        $highConfidence = collect();
        $needsReview = collect();
        $alreadyCovered = collect();
        $knownExcluded = collect();

        foreach ($candidates as $spell) {
            $matchCount = $seenInMatches[$spell->spell_id] ?? 0;

            if ($matchCount === 0) {
                continue; // No real cast evidence at all — not a candidate, just an untagged spell.
            }

            if (in_array($spell->name, self::KNOWN_EXCLUDED_NAMES, true)) {
                $knownExcluded->push(['spell' => $spell, 'matches' => $matchCount]);

                continue;
            }

            $existingSibling = $alreadyTaggedByDisplayName->get($spell->display_name);

            if ($existingSibling) {
                $alreadyCovered->push(['spell' => $spell, 'matches' => $matchCount, 'realSpellId' => $existingSibling->spell_id, 'realCategory' => $existingSibling->dr_category]);

                continue;
            }

            $category = self::HIGH_CONFIDENCE_MAP[$spell->mechanic] ?? null;

            // Snare (mapping to Slow) is the one mechanic already confirmed to carry real,
            // spurious Blizzard-side tags on abilities that have nothing to do with movement
            // speed (see KNOWN_EXCLUDED_NAMES's docblock) — a literal "slow" substring in the
            // spell's own description is required before trusting this specific mapping.
            // Deliberately checks for "slow" itself, not the broader "speed" (which would also
            // match unrelated casting-speed effects like Numbing Poison) or "movement" alone.
            if ($category === 'Slow' && !str_contains(strtolower((string) $spell->description), 'slow')) {
                $category = null;
            }

            if ($category !== null) {
                $highConfidence->push(['spell' => $spell, 'matches' => $matchCount, 'category' => $category]);
            } else {
                $needsReview->push(['spell' => $spell, 'matches' => $matchCount]);
            }
        }

        if ($knownExcluded->isNotEmpty()) {
            $this->newLine();
            $this->info("KNOWN EXCLUDED ({$knownExcluded->count()}) — already investigated and deliberately not tagged in a prior session, skipped:");
            foreach ($knownExcluded->sortByDesc('matches') as $row) {
                $this->line("  {$row['spell']->spell_id} | {$row['spell']->name}  (see KNOWN_EXCLUDED_NAMES docblock for why)");
            }
        }

        if ($alreadyCovered->isNotEmpty()) {
            $this->newLine();
            $this->info("ALREADY COVERED ({$alreadyCovered->count()}) — duplicate spell_id of an already-tagged real ability, skipped (not a new discovery):");
            foreach ($alreadyCovered->sortByDesc('matches') as $row) {
                $this->line("  {$row['spell']->spell_id} | {$row['spell']->name}  -> real copy is {$row['realSpellId']} ({$row['realCategory']}, already tagged)");
            }
        }

        $this->newLine();
        $this->info("HIGH CONFIDENCE ({$highConfidence->count()}) — unambiguous mechanic->dr_category mapping, real cast evidence, no existing same-named tag:");
        foreach ($highConfidence->sortByDesc('matches') as $row) {
            $this->line("  {$row['spell']->spell_id} | {$row['category']} | {$row['spell']->name}  (seen in {$row['matches']} match(es), mechanic={$row['spell']->mechanic})");
        }

        $this->newLine();
        $this->info("NEEDS REVIEW ({$needsReview->count()}) — real cast evidence, but mechanic doesn't map to one confirmed dr_category:");
        foreach ($needsReview->sortByDesc('matches') as $row) {
            $this->line("  {$row['spell']->spell_id} | mechanic={$row['spell']->mechanic} | {$row['spell']->name}  (seen in {$row['matches']} match(es)) — read its description and decide by hand");
        }

        if ($highConfidence->isEmpty()) {
            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->info('Ready-to-paste lines for cc-synergies-overrides.txt (or re-run with --apply to write + re-import automatically):');
            foreach ($highConfidence as $row) {
                $this->line("{$row['spell']->spell_id} | {$row['category']} | | | | | {$row['spell']->name}");
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->applyLines($highConfidence, $patch);

        return self::SUCCESS;
    }

    private function applyLines($highConfidence, Patch $patch): void
    {
        $path = base_path('data/spelldata/cc-synergies-overrides.txt');
        $header = "\n# Added via wow:discover-cc-spells --apply, ".now()->toDateString().
            " — real arena-log cast evidence + unambiguous mechanic->dr_category mapping (see spell-acquisition-model.md).\n";

        $lines = $highConfidence->map(fn ($row) => "{$row['spell']->spell_id} | {$row['category']} | | | | | {$row['spell']->name}")->implode("\n");

        File::append($path, $header.$lines."\n");
        $this->info('Appended '.$highConfidence->count()." line(s) to data/spelldata/cc-synergies-overrides.txt.");

        $this->info("Re-running import:spelldata wow {$patch->build_version} to apply immediately...");

        $result = Process::timeout(300)->run([
            PHP_BINARY, '-d', 'memory_limit=512M', base_path('artisan'), 'import:spelldata', 'wow', $patch->build_version,
        ]);

        if (!$result->successful()) {
            $this->error('Re-import failed — the override lines were written but not yet applied. Output:');
            $this->line($result->errorOutput());

            return;
        }

        $this->info('Re-import complete. Changes are live.');
    }
}
