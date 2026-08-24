<?php

namespace App\Console\Commands;

use App\Http\Services\CcFormulaService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;

/**
 * Hypothetical CC-chain builder for a 3-spec comp — a standalone CLI tool for testing the
 * "simplest version" formula worked out 2026-08-23 before it went anywhere near the live
 * WoW Comps Crowd Control page, per direct instruction ("start building as a tool that we
 * can use on hypotheticals before we bring it into the current crowd control page").
 *
 * The actual algorithm lives in CcFormulaService — extracted the same day the tool was wired
 * into WowComps's Synergies tab, so the live page and this CLI tool share identical logic
 * rather than risking drift between two copies. This class is now just argument parsing +
 * text formatting over that service's structured return value. See CcFormulaService's own
 * docblock for the full rule-by-rule rationale (real opener/transition rate as the primary
 * scoring signal, stealth/out-of-combat gating, pairs_with_category setup steps, kill-target
 * reservation from leftovers, and — added 2026-08-24 — the "next go" second chain) — none of
 * it duplicated here.
 *
 * Usage: php artisan wow:cc-formula priest discipline druid restoration rogue subtlety
 */
class CcFormula extends Command
{
    protected $signature = 'wow:cc-formula
        {class1} {spec1}
        {class2} {spec2}
        {class3} {spec3}
        {--no-real-data : Ignore real opener/transition rates entirely, using only the pre-2026-08-23 cast_type/healer/duration tie-breaks — for direct before/after comparison}';

    protected $description = 'Build a hypothetical CC chain for a 3-spec comp from real, data-confirmed patterns — same logic as the live WoW Comps Crowd Control page';

    public function handle(CcFormulaService $service): int
    {
        $specEntries = [];

        foreach ([1, 2, 3] as $i) {
            $classSlug = $this->argument("class{$i}");
            $specSlug = $this->argument("spec{$i}");

            $class = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->where('slug', $classSlug)->first();

            if (!$class) {
                $this->error("Unknown class slug '{$classSlug}'.");

                return self::FAILURE;
            }

            $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();

            if (!$spec) {
                $this->error("Unknown spec slug '{$specSlug}' for class '{$classSlug}'.");

                return self::FAILURE;
            }

            $specEntries[] = ['class' => $class, 'spec' => $spec];
        }

        $useRealData = !$this->option('no-real-data');
        $result = $service->buildChain($specEntries, $useRealData);

        $this->info("=== Comp: {$result['compLabel']} ===");
        $this->newLine();

        $this->info('--- First go ---');
        $this->printChain($result['primary'], $useRealData);

        $this->newLine();
        $this->info('--- Next go (~20s later — categories reset, anything still on cooldown from the first go is excluded) ---');
        $this->printChain($result['nextGo'], $useRealData);

        return self::SUCCESS;
    }

    private function printChain(array $chain, bool $useRealData): void
    {
        if ($chain['poolEmpty']) {
            $this->warn('No hard-CC (Stun/Silence/Incapacitate/Disorient) abilities found for this comp at all.');

            return;
        }

        $this->info('Suggested healer-lock sequence (never repeats a category while a fresh one is available):');

        foreach ($chain['sequence'] as $i => $entry) {
            $spell = $entry['spell'];
            $durLabel = $entry['durationSeconds'] !== null ? "{$entry['durationSeconds']}s" : 'no curated duration';
            $ct = $entry['castType'] ?? 'unknown';
            $healerFlag = $entry['isHealer'] ? ' [healer-cast]' : '';
            $stealthFlag = $entry['stealthNote'] !== null ? " [{$entry['stealthNote']}]" : '';
            $realRateLabel = !$useRealData ? 'real data ignored (--no-real-data)' : $entry['realRateLabel'];

            $note = $entry['requirementNote'];
            if ($note !== null) {
                match ($note['type']) {
                    'satisfied' => $this->line('  '.($i + 1).'. (requirement already satisfied by step '.$note['satisfiedByStepNumber'].": {$note['satisfiedBySpellName']})"),
                    'setup' => $this->line('  '.($i + 1).'. [setup] '.$note['setupSpell']->display_name.' ('.$note['setupSpell']->dr_category.') — '.$note['setupLabel'].', to hold the target in place first'),
                    'warning' => $this->line('  '.($i + 1).'. ⚠ '.$spell->display_name.' needs a '.$note['neededCategory'].' to actually land reliably — this comp has none available/unused.'),
                };
            }

            $this->line('  '.($i + 1).". {$spell->display_name} ({$spell->dr_category}, {$ct}, {$entry['rangeLabel']}, {$durLabel}) — {$entry['label']}{$healerFlag}{$stealthFlag} [{$realRateLabel}]");

            foreach ($entry['alternates'] as $alt) {
                $altNote = $alt['note'] !== null ? " — {$alt['note']}" : '';
                $this->line('       (alt: '.$alt['spell']->display_name.' — '.$alt['label'].$altNote.')');
            }
        }

        $this->newLine();
        $this->info('Estimated total healer lockdown: '.round($chain['totalDuration'], 1).'s'.($chain['missingDuration'] ? ' (at least one step has no curated pvp_duration_seconds — real total is somewhat higher than this)' : ''));

        $this->newLine();

        if ($chain['killTarget']) {
            $kt = $chain['killTarget'];
            $this->info('Also available to lock the KILL TARGET (left over after the healer-lock sequence above; does not break on damage, so damage can land while it holds):');

            $note = $kt['requirementNote'];
            if ($note !== null) {
                match ($note['type']) {
                    'setup' => $this->line('  requires setup: '.$note['setupSpell']->display_name.' ('.$note['setupSpell']->dr_category.') — '.$note['setupLabel'].', to hold the target in place first'),
                    'warning' => $this->line('  ⚠ '.$kt['spell']->display_name.' needs a '.$note['neededCategory'].' to actually land reliably — this comp has none available/unused.'),
                    default => null,
                };
            }

            $rateLabel = !$useRealData ? 'real data ignored (--no-real-data)' : $kt['realRateLabel'];
            $this->line('  '.$kt['spell']->display_name.' ('.$kt['spell']->dr_category.', '.($kt['castType'] ?? 'cast_type unknown').') — '.$kt['label']." [{$rateLabel}]");
        } else {
            $this->line($chain['noKillTargetMessage']);
        }
    }
}
