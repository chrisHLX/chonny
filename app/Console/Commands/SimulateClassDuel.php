<?php

namespace App\Console\Commands;

use App\Http\Services\DuelSimulatorService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\SpecKitComputer;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generates one "Claude's Counters" matchup — see data/claudes-counters/README.md for the full
 * design. Reads BOTH sides' real ability pools from their own already-written Claude's Guide
 * spellGrid sections (never re-derives a kit independently), resolves them to real current data
 * via SpecKitComputer, runs DuelSimulatorService's deterministic rule-based sim, and writes the
 * result to data/claudes-counters/{classA}-{specA}_vs_{classB}-{specB}.json — the same
 * "precompute once, render from a plain file" pattern as wow:precompute-spell-kits, not a live
 * per-page-load computation.
 *
 * Requires a Claude's Guide to already exist for BOTH specs (data/claudes-guides/{class}/
 * {spec}.json) — per the user's own instruction ("pick 2 classes that you have created guides
 * for"). Fails loudly, not silently, if either guide is missing.
 */
class SimulateClassDuel extends Command
{
    protected $signature = 'wow:simulate-duel
        {classA} {specA} {rangeA : melee|ranged — a small hand-authored hint, not derived from any column}
        {classB} {specB} {rangeB : melee|ranged}
        {--ticks=30 : total alternating actions across both sides}';

    protected $description = "Generate one Claude's Counters matchup from two existing Claude's Guides";

    /** Guide section heading (substring match, case-insensitive) => duel role. */
    private const HEADING_ROLE_MAP = [
        'offensive' => 'offensive',
        'core damage' => 'offensive',
        'core toolkit' => 'offensive',
        'defensive' => 'defensive',
        'healing cooldowns' => 'defensive',
        'crowd control' => 'cc',
        'mobility' => 'mobility',
    ];

    public function handle(SpecKitComputer $kitComputer, ModuleSpellReferenceService $service, TalentSelectionService $talentService)
    {
        $classA = strtolower($this->argument('classA'));
        $specA = strtolower($this->argument('specA'));
        $rangeA = $this->argument('rangeA');
        $classB = strtolower($this->argument('classB'));
        $specB = strtolower($this->argument('specB'));
        $rangeB = $this->argument('rangeB');
        $ticks = (int) $this->option('ticks');

        $sideA = $this->buildSide('A', $classA, $specA, $rangeA, $kitComputer, $service, $talentService);
        $sideB = $this->buildSide('B', $classB, $specB, $rangeB, $kitComputer, $service, $talentService);

        if (!$sideA || !$sideB) {
            return self::FAILURE;
        }

        $sim = new DuelSimulatorService();
        $result = $sim->simulate($sideA, $sideB, $ticks);

        $out = [
            'matchupSlug' => "{$classA}-{$specA}_vs_{$classB}-{$specB}",
            'sideA' => ['classSlug' => $classA, 'specSlug' => $specA, 'title' => $sideA['title'], 'range' => $rangeA],
            'sideB' => ['classSlug' => $classB, 'specSlug' => $specB, 'title' => $sideB['title'], 'range' => $rangeB],
            'generatedAt' => now()->toIso8601String(),
            'tickSeconds' => DuelSimulatorService::TICK_SECONDS,
            'beats' => array_map(fn ($b) => $this->beatToArray($b), $result['beats']),
            'summary' => $result['summary'],
        ];

        $path = base_path("data/claudes-counters/{$out['matchupSlug']}.json");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Wrote {$path}");
        $this->line("Beats: {$result['summary']['ticks_run']}, final pressure — {$sideA['title']}: {$result['summary']['sideA']['final_pressure']}, {$sideB['title']}: {$result['summary']['sideB']['final_pressure']}");

        return self::SUCCESS;
    }

    private function buildSide(string $label, string $classSlug, string $specSlug, string $range, SpecKitComputer $kitComputer, ModuleSpellReferenceService $service, TalentSelectionService $talentService): ?array
    {
        $guidePath = base_path("data/claudes-guides/{$classSlug}/{$specSlug}.json");
        if (!File::exists($guidePath)) {
            $this->error("No Claude's Guide exists for {$classSlug}/{$specSlug} — write one first (this feature deliberately only simulates specs already covered).");

            return null;
        }

        $guide = json_decode(File::get($guidePath), true);

        $class = GameClass::where('slug', $classSlug)->first();
        $spec = $class ? Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first() : null;
        if (!$spec) {
            $this->error("{$classSlug}/{$specSlug} did not resolve to a real class/spec in the DB.");

            return null;
        }

        $roleSpellIds = ['offensive' => [], 'defensive' => [], 'cc' => [], 'mobility' => []];
        foreach ($guide['sections'] as $section) {
            if (($section['type'] ?? null) !== 'spellGrid') {
                continue;
            }
            $heading = strtolower($section['heading'] ?? '');
            $role = null;
            foreach (self::HEADING_ROLE_MAP as $needle => $mappedRole) {
                if (str_contains($heading, $needle)) {
                    $role = $mappedRole;
                    break;
                }
            }
            if ($role !== null) {
                $roleSpellIds[$role] = array_merge($roleSpellIds[$role], $section['spellIds']);
            }
        }

        $entriesByRole = [];
        foreach ($roleSpellIds as $role => $spellIds) {
            $entriesByRole[$role] = $kitComputer->resolveEntriesForSpellIds($spellIds, $spec, $service, $talentService);
        }

        $sim = new DuelSimulatorService();

        return $sim->buildCombatant(
            label: $label,
            classSlug: $classSlug,
            specSlug: $specSlug,
            title: $guide['title'] ?? Str::title("{$specSlug} {$classSlug}"),
            range: $range,
            roleSpellIds: $roleSpellIds,
            entriesByRole: $entriesByRole,
        );
    }

    private function beatToArray(array $beat): array
    {
        return [
            'tick' => $beat['tick'],
            'time_seconds' => $beat['time_seconds'],
            'actor' => $beat['actor'],
            'action_type' => $beat['action_type'],
            'ability' => $beat['ability'],
            'note' => $beat['note'],
            'pressure' => $beat['pressure'],
            'cc_chain_depth' => $beat['cc_chain_depth'],
        ];
    }
}
