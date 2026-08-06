<?php

namespace App\Console\Commands;

use App\Http\Services\MurlokTalentImportService;
use App\Http\Services\TalentSelectionService;
use App\Models\Specialization;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Populates a spec's admin-curated default TalentBuild from murlok.io's public per-spec/bracket
 * guide page. See MurlokTalentImportService's docblock for why this reads that page's rendered
 * pick-count heatmap rather than murlok's WASM-generated per-character export string, or
 * Blizzard's own (currently broken for this purpose) Character Specializations API.
 *
 * Always previews first and requires --apply to actually write — same "preview before trusting"
 * posture as TalentSelector's Blizzard-string import flow. Single-spec runs are the default;
 * --all loops every WoW spec (40 as of 2026-08 — see class docblock note below) for a full
 * refresh, still explicitly a manual/on-demand action, never scheduled — see --all's own note.
 */
class ImportMurlokDefaults extends Command
{
    protected $signature = 'wow:import-murlok-defaults
        {spec? : Specialization name, e.g. "Discipline" — omit when using --all}
        {bracket=3v3 : murlok bracket segment — 2v2, 3v3, solo-shuffle, blitz, rbg}
        {--class= : Class name, required only if the spec name is ambiguous across classes}
        {--apply : Actually write the result to the default TalentBuild(s). Without this flag, only previews.}
        {--all : Loop every WoW spec instead of one. 1s delay between requests — respect for a live third-party site, not a technical requirement.}';

    protected $description = 'Preview or apply spec default TalentBuild(s) sourced from murlok.io\'s meta-build guide page.';

    public function handle(MurlokTalentImportService $murlok, TalentSelectionService $talentService): int
    {
        $bracket = $this->argument('bracket');

        if ($this->option('all')) {
            return $this->handleAll($murlok, $talentService, $bracket);
        }

        if (!$this->argument('spec')) {
            $this->error('The spec argument is required unless --all is passed.');

            return self::FAILURE;
        }

        $spec = $this->resolveSpec();

        if (!$spec) {
            return self::FAILURE;
        }

        return $this->importOne($murlok, $talentService, $spec, $bracket, verbose: true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs every WoW spec sequentially with a 1s pause between murlok.io requests — this is a
     * courtesy to a third-party site being hit ~40 times in a row, not something the site
     * requires of us (its robots.txt allows crawling; this is just not hammering it for no
     * reason). Per-spec failures (a 404, an unresolvable hero tree, etc.) are caught and reported
     * in the summary table rather than aborting the whole run — one bad spec shouldn't block the
     * other 39.
     */
    private function handleAll(MurlokTalentImportService $murlok, TalentSelectionService $talentService, string $bracket): int
    {
        $specs = Specialization::whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->with('gameClass')
            ->get()
            ->sortBy(fn ($s) => $s->gameClass->name.$s->name);

        $this->info("Running {$specs->count()} specs against bracket '{$bracket}'".($this->option('apply') ? ' (applying)' : ' (preview only)').'...');
        $this->line('');

        $results = [];

        foreach ($specs as $i => $spec) {
            $label = "{$spec->gameClass->name} / {$spec->name}";
            $this->line("[".($i + 1)."/{$specs->count()}] {$label}...");

            $results[] = $this->importOne($murlok, $talentService, $spec, $bracket, verbose: false, label: $label);

            if ($i < $specs->count() - 1) {
                sleep(1);
            }
        }

        $this->line('');
        $this->table(
            ['Spec', 'Status', 'Hero Tree', 'Class', 'Spec', 'Hero', 'PvP'],
            collect($results)->map(fn ($r) => [
                $r['label'],
                $r['ok'] ? ($this->option('apply') ? 'applied' : 'ok') : 'FAILED',
                $r['heroTreeName'] ?? '—',
                $r['ok'] ? "{$r['classNodesSelected']}/{$r['classNodesTotal']}" : '—',
                $r['ok'] ? "{$r['specNodesSelected']}/{$r['specNodesTotal']}" : '—',
                $r['ok'] ? "{$r['heroNodesSelected']}/{$r['heroNodesTotal']}" : '—',
                $r['ok'] ? count($r['pvpTalentsSelected']) : '—',
            ])->all()
        );

        $failed = collect($results)->where('ok', false);

        if ($failed->isNotEmpty()) {
            $this->line('');
            $this->warn('Failed specs:');
            foreach ($failed as $r) {
                $this->line("  - {$r['label']}: {$r['error']}");
            }
        }

        $this->line('');
        $this->info(collect($results)->where('ok', true)->count()." / {$specs->count()} succeeded.");

        return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array|bool In --all mode returns the result array (always, even on failure); in single-spec mode returns bool and prints full detail. */
    private function importOne(MurlokTalentImportService $murlok, TalentSelectionService $talentService, Specialization $spec, string $bracket, bool $verbose, ?string $label = null): array|bool
    {
        $label ??= "{$spec->gameClass->name} / {$spec->name}";

        if ($verbose) {
            $this->info("Fetching murlok.io guide for {$label} / {$bracket}...");
        }

        try {
            $preview = $murlok->preview($spec, $bracket);
        } catch (RuntimeException $e) {
            if ($verbose) {
                $this->error($e->getMessage());

                return false;
            }

            return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
        }

        if ($verbose) {
            $this->line('');
            $this->line("URL: {$preview['url']}");
            $this->line("Hero tree resolved: ".($preview['heroTreeName'] ?? '— not matched, see warnings below —'));
            $this->line("Class talents selected: {$preview['classNodesSelected']} / {$preview['classNodesTotal']} nodes");
            $this->line("Spec talents selected: {$preview['specNodesSelected']} / {$preview['specNodesTotal']} nodes");
            $this->line("Hero talents selected: {$preview['heroNodesSelected']} / {$preview['heroNodesTotal']} nodes");
            $this->line('PvP talents selected: '.(empty($preview['pvpTalentsSelected']) ? '(none)' : implode(', ', $preview['pvpTalentsSelected'])));

            if (!empty($preview['unmatchedNames'])) {
                $this->line('');
                $this->warn('Unmatched (present in one source, not the other — not applied to anything):');
                foreach ($preview['unmatchedNames'] as $name) {
                    $this->line("  - {$name}");
                }
            }
        }

        if (!$this->option('apply')) {
            if ($verbose) {
                $this->line('');
                $this->comment('Preview only — re-run with --apply to write this to the spec\'s default TalentBuild.');

                return true;
            }

            return array_merge($preview, ['label' => $label, 'ok' => true]);
        }

        $build = $murlok->apply($preview, $talentService);

        if ($verbose) {
            $this->line('');
            $this->info("Applied — TalentBuild #{$build->id} is now the default for {$spec->name} (patch #{$preview['patchId']}).");

            return true;
        }

        return array_merge($preview, ['label' => $label, 'ok' => true]);
    }

    private function resolveSpec(): ?Specialization
    {
        $query = Specialization::where('name', $this->argument('spec'));

        if ($className = $this->option('class')) {
            $query->whereHas('gameClass', fn ($q) => $q->where('name', $className));
        }

        $matches = $query->with('gameClass')->get();

        if ($matches->isEmpty()) {
            $this->error("No specialization named '{$this->argument('spec')}' found".($this->option('class') ? " for class '{$this->option('class')}'" : '').'.');

            return null;
        }

        if ($matches->count() > 1) {
            $this->error("Spec name '{$this->argument('spec')}' is ambiguous — pass --class to disambiguate. Matches: ".
                $matches->map(fn ($s) => "{$s->gameClass->name} / {$s->name}")->implode(', '));

            return null;
        }

        return $matches->first();
    }
}
