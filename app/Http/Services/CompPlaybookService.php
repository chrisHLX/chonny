<?php

namespace App\Http\Services;

use App\Console\Commands\BuildGoCooldowns;
use App\Support\CooldownTabs;
use Illuminate\Support\Facades\File;

/**
 * "How to play it": the comp page's plain guide to playing three chosen specs, for a player new
 * to arena (2026-10-05).
 *
 * Built because of two new players in Chriso's Paladin games that day (match-review-analysis.md,
 * 5 Oct): the one go they played to a plan (stun the healer, trap her, press Combustion and
 * Bestial Wrath together) killed in five seconds, and the rest of their games showed basics
 * nobody had told them — cooldowns pressed apart, crowd control on the player they were hitting,
 * the player killing them never controlled. The page's tabs list a kit; this says what to do with
 * it, in the order a game asks for it.
 *
 * Every line is built from what the page has already computed, so nothing here is a second source
 * of truth:
 * - the healer lock is CcFormulaService's chain (the Crowd Control tab's "Example CC Chains"),
 *   cut to the first three steps a new player can actually land;
 * - the burst is what each spec presses in its goes, counted from every measured game
 *   (wow:go-cooldowns, a committed file), resolved through the page's own kit and build;
 * - breakable control, kicks and peels are getSynergiesProperty()'s groups.
 *
 * What it does not say, on purpose: which defensives can be cast on a teammate. Nothing in the
 * data separates a personal defensive from an external one (MatchupProfileService's docblock),
 * and a guessed split would tell a DPS to wait for an external that cannot reach him.
 */
class CompPlaybookService
{
    /** Control that ends when its target takes damage. Stuns and silences hold (rule 9). */
    public const BREAKS_ON_DAMAGE = ['Incapacitate', 'Disorient'];

    /**
     * Control in a breaking category that does not break: its target cannot be damaged while it
     * holds. By external spell id. Still never on the kill target, for the opposite reason.
     */
    public const DAMAGE_IMMUNE = [33786 => 'Cyclone'];

    /** Crowd control proper. A root on an offensive spell (The Hunt) does not make it control. */
    private const HARD_CC = ['Stun', 'Silence', 'Incapacitate', 'Disorient'];

    /** A new player lands three in a row or none; the formula's fourth step is for later. */
    private const LOCK_STEPS = 3;

    private const DEFENSIVES_PER_PLAYER = 4;

    /** A spec's go buttons are read from play only once this many goes, by this many players. */
    private const MIN_GOES = 20;

    private const MIN_PLAYERS = 5;

    /** Pressed in at least this share of the spec's goes. */
    private const DPS_SHARE = 0.5;

    private const HEALER_SHARE = 0.6;

    private ?array $goCooldowns = null;

    private const MEDALLION = "Gladiator's Medallion";

    /**
     * @param  array  $comp  WowComps::getCompProperty()
     * @param  array<int, string>  $roles  spec id => 'healer' | 'dps' | 'tank'
     * @param  ?array  $chain  WowComps::getSuggestedChainProperty()
     * @param  array  $synergies  WowComps::getSynergiesProperty()
     */
    public function build(array $comp, array $roles, ?array $chain, array $synergies): ?array
    {
        $members = collect($comp)->filter(fn ($m) => $m['spec'] && $m['class']);

        if ($members->count() < count($comp)) {
            return null;
        }

        $labelToIndex = $members->mapWithKeys(fn ($m, $mi) => ["{$m['spec']->name} {$m['class']->name}" => $mi])->all();
        $isHealer = fn (int $mi) => ($roles[$comp[$mi]['spec']->id] ?? 'dps') === 'healer';

        return [
            'lock' => $this->healerLock($chain['primary'] ?? null, $labelToIndex),
            'burst' => $this->burst($comp, $isHealer),
            'keep' => $this->killTargetControl($chain['primary'] ?? null, $labelToIndex),
            'breakable' => $this->listed($synergies, fn ($spell) => in_array($synergies['dr_by_id'][$spell->id] ?? null, self::BREAKS_ON_DAMAGE, true),
                $synergies['groups']['Diminishing Returns Groups'] ?? collect()),
            'defensives' => $this->defensives($comp),
            'kicks' => $this->listed($synergies, fn () => true, $synergies['interrupts']),
            'peels' => $this->listed($synergies, fn () => true, $synergies['peels']),
        ];
    }

    /**
     * The first steps of the formula's chain, each with the one thing a new player needs to know
     * about it: whether it can be kicked, whether hitting the healer ends it, and that a different
     * kind of control is what keeps the next one full length.
     */
    private function healerLock(?array $primary, array $labelToIndex): array
    {
        if (! $primary || $primary['poolEmpty']) {
            return ['steps' => [], 'seconds' => 0.0];
        }

        $steps = [];
        $seconds = 0.0;

        foreach (array_slice($primary['sequence'], 0, self::LOCK_STEPS) as $i => $step) {
            $spell = $step['spell'];
            $dr = $spell->dr_category;
            $notes = [];

            if ($step['stealthNote']) {
                $notes[] = 'Only from stealth, so only as the game opens.';
            } elseif ($i > 0) {
                $notes[] = 'A different kind of control from the one before, so it lasts its full time.';
            }

            $notes[] = match ($step['castType']) {
                'instant' => 'Instant: it cannot be kicked.',
                'cast' => 'Has a cast time: their kick can stop it, so cast it while they are busy.',
                default => null,
            };

            if (isset(self::DAMAGE_IMMUNE[$spell->spell_id])) {
                $notes[] = 'Their healer cannot be hit or healed while it holds.';
            } elseif (in_array($dr, self::BREAKS_ON_DAMAGE, true)) {
                $notes[] = 'Breaks if their healer takes damage.';
            }

            $seconds += (float) ($step['durationSeconds'] ?? 0);

            $steps[] = [
                'spell' => $spell,
                'mi' => $labelToIndex[$step['label']] ?? null,
                'dr' => $dr,
                'seconds' => $step['durationSeconds'],
                'notes' => array_values(array_filter($notes)),
            ];
        }

        return ['steps' => $steps, 'seconds' => $seconds];
    }

    /**
     * Each player's buttons for the go, as counted from play (wow:go-cooldowns): what the spec
     * presses in at least half its goes, crowd control left out (Chaos Nova is "offensive" in the
     * tags and a stun on the page). Neither guess from the spell data names a spec's big button:
     * the longest cooldown made Shattering Throw Arms's lead, and the classifier's Buff/Spell label
     * made Tricks of the Trade Subtlety's. A spec with too few games measured says so instead.
     *
     * A healer is listed only for what it presses in most goes (Power Infusion), since its own
     * part of the go is the crowd control above. Cadence is the slowest and fastest DPS button:
     * everything lines up again at the slowest. Both are upper bounds (rule 34): talents and
     * spending bring real goes round sooner.
     */
    private function burst(array $comp, callable $isHealer): array
    {
        $seen = $this->goCooldowns();
        $players = [];

        foreach ($comp as $mi => $member) {
            $healer = $isHealer($mi);
            $data = $seen['specs'][(string) $member['spec']->external_spec_id] ?? null;
            $measured = $data && $data['goes'] >= self::MIN_GOES && $data['players'] >= self::MIN_PLAYERS;

            $byName = collect($member['entries'])
                ->sortByDesc(fn ($e) => ($e['isSelected'] ?? true) ? 1 : 0)
                ->unique(fn ($e) => $e['spell']->display_name)
                ->keyBy(fn ($e) => $e['spell']->display_name);

            $buttons = collect($measured ? $data['spells'] : [])
                ->filter(fn ($s) => $s['share'] >= ($healer ? self::HEALER_SHARE : self::DPS_SHARE))
                ->map(fn ($s) => ['entry' => $byName->get($s['name']), 'share' => $s['share']])
                ->filter(fn ($b) => $b['entry'] && ! in_array($b['entry']['drCategory'], self::HARD_CC, true))
                ->take(3)
                ->values()
                ->all();

            if ($healer && ! $buttons) {
                continue;
            }

            $players[] = [
                'mi' => $mi,
                'healer' => $healer,
                'buttons' => $buttons,
                'goes' => $measured ? $data['goes'] : ($data['goes'] ?? 0),
            ];
        }

        $cooldowns = collect($players)
            ->reject(fn ($p) => $p['healer'])
            ->flatMap(fn ($p) => array_map(fn ($b) => $b['entry']['cooldown']['seconds'] ?? null, $p['buttons']))
            ->filter();

        return [
            'players' => $players,
            'every' => $cooldowns->max(),
            'fastest' => $cooldowns->min() < $cooldowns->max() ? $cooldowns->min() : null,
            'rounds' => $seen['rounds'] ?? 0,
        ];
    }

    private function goCooldowns(): array
    {
        $path = base_path(BuildGoCooldowns::PATH);

        return $this->goCooldowns ??= File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
    }

    private function killTargetControl(?array $primary, array $labelToIndex): ?array
    {
        $kill = $primary['killTarget'] ?? null;

        return $kill ? ['spell' => $kill['spell'], 'mi' => $labelToIndex[$kill['label']] ?? null] : null;
    }

    /**
     * Each player's own defensives, longest cooldown first. A Mixed spell (Bestial Wrath) is left
     * to the burst: listed here it reads as a reason to hold it. The Medallion is everyone's and
     * is said once, on its own.
     */
    private function defensives(array $comp): array
    {
        $out = [];

        foreach ($comp as $mi => $member) {
            $out[$mi] = collect($member['entries'])
                ->filter(fn ($e) => ($e['isSelected'] ?? true)
                    && CooldownTabs::isEntry($e, 'defensive')
                    && ! ($e['offensiveDefensive']['offensive'] ?? false)
                    && $e['spell']->display_name !== self::MEDALLION)
                ->unique(fn ($e) => $e['spell']->display_name)
                ->sortByDesc(fn ($e) => $e['cooldown']['seconds'] ?? 0)
                ->take(self::DEFENSIVES_PER_PLAYER)
                ->values()
                ->all();
        }

        return $out;
    }

    /** Spells from one of the Synergies groups, with their owner and cooldown, one per name. */
    private function listed(array $synergies, callable $keep, $spells): array
    {
        return collect($spells)
            ->filter($keep)
            ->unique('display_name')
            ->map(fn ($spell) => [
                'spell' => $spell,
                'immune' => isset(self::DAMAGE_IMMUNE[$spell->spell_id]),
                'mi' => $synergies['owner_map'][$spell->id] ?? null,
                'cooldown' => $synergies['cooldown_by_id'][$spell->id] ?? null,
            ])
            ->values()
            ->all();
    }
}
