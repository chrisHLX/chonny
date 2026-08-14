<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Diffs an accumulated data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt file (built by
 * wow:extract-arena-spells from real match casts) against spell_class_availability, same
 * spirit as wow:diff-spellbook's Direction A/C/D but sourced from match history instead of
 * an addon-captured character export.
 *
 * IMPORTANT — this is NOT a strict replacement for the spellbook_snapshots pipeline, and the
 * two have a real, asymmetric coverage difference worth understanding before trusting this
 * output the same way:
 *   - A spellbook_snapshots export is a FULL spellbook — every known spell, whether cast in
 *     that session or not. Absence there (wow:diff-spellbook's Direction B) is real signal.
 *   - This file only ever contains spells that were actually SEEN CAST across whatever
 *     matches have been fed into it via wow:extract-arena-spells. A single ~4 minute arena
 *     match surfaces a small fraction of a real character's kit (a cooldown that didn't come
 *     off, a defensive never needed) — accumulating more matches over time narrows this gap,
 *     but "not in this file" is never treated as "this spec doesn't have this spell" here.
 *     For that reason, unlike wow:diff-spellbook, this command has NO "not-in-usage-file"
 *     direction at all — it only ever reports on spells that WERE positively seen cast,
 *     which is the one thing a partial cast list is strong, not weak, evidence for.
 *
 * What it reports, per spell_id in the usage file:
 *   - CONFIRMED            — already has an explicit spell_class_availability row for this
 *                             exact spec. No action.
 *   - PROMOTION_CANDIDATE  — only has an ambiguous spec_id=NULL ('baseline') row, but real
 *                             match evidence shows this exact spec casting it.
 *   - CONTRADICTION        — has explicit availability rows, but only for OTHER spec(s), not
 *                             this one or NULL. Real match evidence says this spec ALSO uses
 *                             it — the existing tag(s) aren't wrong, just under-scoped (same
 *                             pattern CLAUDE.md already documents for Freezing Trap/Hammer of
 *                             Justice: a genuinely multi-spec ability that only had one
 *                             spec's evidence recorded).
 *   - MISSING_ENTIRELY     — no spell_class_availability rows at all for this spell_id, any
 *                             spec, any source. Likely a real gap in the base import, or a
 *                             duplicate-copy spell_id (see CLAUDE.md's many documented cases
 *                             of one ability spanning several spell_id records) — not
 *                             actionable via this file either way, never included in --apply.
 *
 * Both PROMOTION_CANDIDATE and CONTRADICTION are, mechanically, the same action: add a
 * `verified_override` line for (this spell, this class, this spec) — CONTRADICTION just also
 * means an existing different-spec line stays untouched alongside it. Both print in the
 * exact `spell_id | class_slug | spec_slug | name` shape baseline-spec-overrides.txt uses.
 *
 * `--apply` (added 2026-08-14, per direct user instruction — "just apply them, I'll review
 * them in the browser"): appends every PROMOTION_CANDIDATE/CONTRADICTION line to
 * data/spelldata/baseline-spec-overrides.txt with a provenance comment, then runs
 * `import:spelldata` automatically so the change is live immediately. Same preview-then-apply
 * shape as wow:import-murlok-defaults's own --apply flag, except here "preview" is this
 * command's normal (non---apply) printed output, reviewed live on the site rather than via a
 * saved report file — a deliberate, explicit choice, not a default this project would pick on
 * its own (every other override file in data/spelldata/ is still hand-verified line by line;
 * this flag exists because arena-log positive-cast evidence was judged trustworthy enough by
 * the user to skip that step for this specific tool going forward).
 *
 * Usage:
 *   php artisan wow:diff-arena-spells rogue subtlety
 *   php artisan wow:diff-arena-spells deathknight unholy --apply
 */
class DiffArenaSpells extends Command
{
    protected $signature = 'wow:diff-arena-spells {classSlug} {specSlug}
        {--apply : Append PROMOTION_CANDIDATE/CONTRADICTION lines to baseline-spec-overrides.txt and re-import immediately}
        {--no-reimport : With --apply, write the lines but skip the automatic re-import — for batch callers (e.g. wow:discover-all-specs) that run one import at the end instead of once per spec}';

    protected $description = 'Diff an accumulated arena-log cast-spell list against spell_class_availability for that class/spec';

    public function handle(): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');

        $game = Game::where('slug', 'wow')->first();
        if (!$game) {
            $this->error("No 'wow' game row found — has import:spelldata ever been run?");

            return self::FAILURE;
        }

        $class = GameClass::where('game_id', $game->id)->where('slug', $classSlug)->first();
        if (!$class) {
            $this->error("No class found for slug '{$classSlug}'.");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();
        if (!$spec) {
            $this->error("No spec found for slug '{$specSlug}' under {$class->name}.");

            return self::FAILURE;
        }

        $patch = $game->currentPatch ?? $game->patches()->latest('id')->first();
        if (!$patch) {
            $this->error("No patch found for game '{$game->slug}'.");

            return self::FAILURE;
        }

        $path = base_path("data/arena-logs/spell-usage/{$classSlug}/{$specSlug}.txt");
        if (!File::exists($path)) {
            $this->error("No usage file at data/arena-logs/spell-usage/{$classSlug}/{$specSlug}.txt — run wow:extract-arena-spells against a match first.");

            return self::FAILURE;
        }

        $usedSpells = [];
        foreach (File::lines($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $usedSpells[(int) $parts[0]] = $parts[1];
            }
        }

        $this->info("Diffing ".count($usedSpells)." cast-seen spells for {$class->name}/{$spec->name} against patch {$patch->build_version}.");
        $this->newLine();

        $counts = ['CONFIRMED' => 0, 'PROMOTION_CANDIDATE' => 0, 'CONTRADICTION' => 0, 'MISSING_ENTIRELY' => 0];
        $applyLines = [];

        foreach ($usedSpells as $externalSpellId => $name) {
            $spell = Spell::where('patch_id', $patch->id)->where('spell_id', $externalSpellId)->first();

            if (!$spell) {
                $this->line("MISSING_ENTIRELY: {$externalSpellId} | {$name}  (not even a spells row for this patch)");
                $counts['MISSING_ENTIRELY']++;

                continue;
            }

            $rows = SpellClassAvailability::where('spell_id', $spell->id)->get();

            if ($rows->isEmpty()) {
                $this->line("MISSING_ENTIRELY: {$externalSpellId} | {$spell->name}");
                $counts['MISSING_ENTIRELY']++;

                continue;
            }

            $hasExplicitForThisSpec = $rows->contains(fn ($r) => $r->spec_id === $spec->id);

            if ($hasExplicitForThisSpec) {
                $counts['CONFIRMED']++;

                continue;
            }

            $hasAmbiguousNull = $rows->contains(fn ($r) => $r->spec_id === null);
            $explicitOtherSpecIds = $rows->whereNotNull('spec_id')->pluck('spec_id')->unique()->values();
            $line = "{$externalSpellId} | {$classSlug} | {$specSlug} | {$spell->name}";

            if ($hasAmbiguousNull && $explicitOtherSpecIds->isEmpty()) {
                $this->line("PROMOTION_CANDIDATE: {$line}");
                $applyLines[] = $line;
                $counts['PROMOTION_CANDIDATE']++;

                continue;
            }

            $otherNames = $explicitOtherSpecIds->map(fn ($id) => Specialization::find($id)?->name ?? "id={$id}")->implode(', ');
            $this->line("CONTRADICTION: {$externalSpellId} | {$spell->name}  (tagged for: {$otherNames} — not {$spec->name} or ambiguous)");
            $applyLines[] = $line;
            $counts['CONTRADICTION']++;
        }

        $this->newLine();
        $this->info('CONFIRMED: '.$counts['CONFIRMED'].'  PROMOTION_CANDIDATE: '.$counts['PROMOTION_CANDIDATE'].'  CONTRADICTION: '.$counts['CONTRADICTION'].'  MISSING_ENTIRELY: '.$counts['MISSING_ENTIRELY']);

        if ($applyLines === []) {
            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->info('Ready-to-paste lines for baseline-spec-overrides.txt (or re-run with --apply to write + re-import automatically):');
            foreach ($applyLines as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->applyLines($class, $spec, $applyLines, !$this->option('no-reimport'));

        return self::SUCCESS;
    }

    private function applyLines(GameClass $class, Specialization $spec, array $lines, bool $reimport = true): void
    {
        $path = base_path('data/spelldata/baseline-spec-overrides.txt');
        $header = "\n# Added via wow:diff-arena-spells --apply, ".now()->toDateString().
            " — {$class->name}/{$spec->name}, real arena-log cast evidence (see arena-log-api.md).\n";

        File::append($path, $header.implode("\n", $lines)."\n");
        $this->info('Appended '.count($lines)." line(s) to data/spelldata/baseline-spec-overrides.txt.");

        if (!$reimport) {
            $this->line('(--no-reimport: skipping the automatic re-import — run import:spelldata once after the batch.)');

            return;
        }

        $patch = Patch::where('is_current', true)->first();

        if (!$patch) {
            $this->warn('No current patch found — skipping automatic re-import. Run import:spelldata manually.');

            return;
        }

        $this->info("Re-running import:spelldata wow {$patch->build_version} to apply immediately...");

        // -d memory_limit=512M: the default 128M CLI limit reliably exhausts on a full
        // re-import (documented gotcha, CLAUDE.md's "Two more reported bugs..." section) —
        // set unconditionally rather than relying on the caller's own php.ini.
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
