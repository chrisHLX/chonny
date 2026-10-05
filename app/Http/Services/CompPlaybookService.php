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
 * - the kill-target control is where each stun and silence lands in real goes (the same file),
 *   rounded out from the kit for spells too rarely seen;
 * - kicks and peels are getSynergiesProperty()'s groups.
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

    /** One step per kind of control; there are four kinds of crowd control proper. */
    private const LOCK_STEPS = 4;

    /** The order a lock is laid out in: the instant stun opens, the long ones follow. */
    private const LOCK_ORDER = ['Stun', 'Silence', 'Incapacitate', 'Disorient'];

    /** A spec's signature runs are read from play once it has controlled a healer in this many goes. */
    private const MIN_HEALER_GOES = 10;

    private const DEFENSIVES_PER_PLAYER = 4;

    /** A spec's go buttons are read from play only once this many goes, by this many players. */
    private const MIN_GOES = 20;

    private const MIN_PLAYERS = 5;

    /** Pressed in at least this share of the spec's goes. */
    private const DPS_SHARE = 0.5;

    private const HEALER_SHARE = 0.6;

    private ?array $goCooldowns = null;

    /** A control spell's placement is read from play once it has landed this often on a healer or a target. */
    private const MIN_PLACEMENTS = 20;

    /** Players whose habit is counted before a spell's placement is read from play. */
    private const MIN_VOTERS = 2;

    /** The mean player's share on the target: at or above this, a kill-target spell; between
     *  SPLIT_VOTE and this, players use it both ways and the guide shows both. */
    private const TARGET_VOTE = 0.6;

    private const SPLIT_VOTE = 0.4;

    private const MELEE_YARDS = 10;

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

        $lock = $this->healerLock($comp, $chain['primary'] ?? null, $labelToIndex);

        return [
            'lock' => $lock,
            'burst' => $this->burst($comp, $isHealer),
            'keep' => $this->killTargetControl($comp, $isHealer, collect($lock['steps'])->flatMap(fn ($s) => array_map(fn ($o) => $o['spell']->display_name, $s['options']))->all()),
            'defensives' => $this->defensives($comp),
            'kicks' => $this->listed($synergies, fn () => true, $synergies['interrupts']),
            'peels' => $this->listed($synergies, fn () => true, $synergies['peels']),
        ];
    }

    /**
     * Their healer's lock, as the comp's specs' signature combos put together (Chriso,
     * 2026-10-05): a Hunter opens "Intimidation > Freezing Trap", a Disc Priest "Psychic Scream",
     * so Jungle locks with stun, trap, fear without anyone having to think about it.
     *
     * Each player brings its spec's most common run of control on the healer in real goes
     * (wow:go-cooldowns, "healerRuns"), skipping any run that holds a stun real games put on the
     * kill target (onKillTarget(): Kidney Shot). A spec too rarely seen brings what the CC formula
     * gave it instead. The runs are then laid out one kind of control per step, stuns first:
     * two players with the same kind become alternatives on one step ("Fear or Cyclone"), since
     * the second would land at half length on the same healer.
     *
     * Not seen yet: roots, and Solar Beam's silence, which the goes do not record as control, so
     * a Balance Druid's "root, beam" cannot come from play.
     */
    private function healerLock(array $comp, ?array $primary, array $labelToIndex): array
    {
        $picked = [];   // [mi, spell, share|null] in the order each player presses them

        foreach ($comp as $mi => $member) {
            $kit = collect($member['entries'])
                ->filter(fn ($e) => ($e['isSelected'] ?? true) && in_array($e['drCategory'], self::HARD_CC, true))
                ->keyBy(fn ($e) => $e['spell']->display_name);
            $data = $this->goCooldowns()['specs'][(string) $member['spec']->external_spec_id] ?? null;
            $run = null;

            if ($data && ($data['healerGoes'] ?? 0) >= self::MIN_HEALER_GOES) {
                $run = collect($data['healerRuns'] ?? [])->first(fn ($r) => collect($r['chain'])
                    ->every(fn ($name) => $kit->has($name) && ! $this->onKillTarget($name, $kit[$name]['spell'])));
            }

            if ($run) {
                foreach ($run['chain'] as $name) {
                    $picked[] = ['mi' => $mi, 'entry' => $kit[$name], 'share' => $run['share']];
                }

                continue;
            }

            // Too rarely seen: the formula's choice for this player, if it made one.
            foreach ($primary['sequence'] ?? [] as $step) {
                if (($labelToIndex[$step['label']] ?? null) === $mi && $kit->has($step['spell']->display_name)
                    && ! $this->onKillTarget($step['spell']->display_name, $step['spell'])) {
                    $picked[] = ['mi' => $mi, 'entry' => $kit[$step['spell']->display_name], 'share' => null];

                    break;
                }
            }
        }

        $slots = collect($picked)
            ->groupBy(fn ($p) => $p['entry']['drCategory'])
            ->sortBy(fn ($group, $dr) => array_search($dr, self::LOCK_ORDER, true))
            ->take(self::LOCK_STEPS);

        $steps = [];
        $seconds = 0.0;

        foreach ($slots->values() as $i => $group) {
            $options = $group->unique(fn ($p) => $p['entry']['spell']->display_name)
                ->map(fn ($p) => ['spell' => $p['entry']['spell'], 'mi' => $p['mi'], 'share' => $p['share'],
                    'split' => in_array($p['entry']['drCategory'], ['Stun', 'Silence'], true)
                        && $this->verdict($p['entry']['spell']->display_name, $p['entry']['spell']) === 'split'])
                ->values()->all();
            $spell = $options[0]['spell'];
            $dr = $group->first()['entry']['drCategory'];
            $notes = [];

            if ($spell->requires_stealth) {
                $notes[] = 'Only from stealth, so only as the game opens.';
            } elseif ($i > 0) {
                $notes[] = 'A different kind of control from the one before, so it lasts its full time.';
            }

            $notes[] = match ($spell->cast_type) {
                'instant' => 'Instant: it cannot be kicked.',
                'cast' => 'Has a cast time: their kick can stop it, so cast it while they are busy.',
                default => null,
            };

            if (isset(self::DAMAGE_IMMUNE[$spell->spell_id])) {
                $notes[] = 'Their healer cannot be hit or healed while it holds.';
            } elseif (in_array($dr, self::BREAKS_ON_DAMAGE, true)) {
                $notes[] = 'Breaks if their healer takes damage.';
            }

            if (count($options) > 1) {
                $notes[] = 'One of these, not both: the same kind twice on her lasts half as long.';
            }

            $seconds += (float) ($spell->pvp_duration_seconds ?? 0);

            $steps[] = [
                'options' => $options,
                'dr' => $dr,
                'seconds' => $spell->pvp_duration_seconds,
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

    /**
     * Control for the player being killed: stuns and silences (they hold through damage) that are
     * not in the healer lock, chosen from where they land in real goes first and the kit second.
     *
     * The formula's single reserved pick was wrong both ways (Chriso, 2026-10-05): it kept Binding
     * Shot for the kill target, which play splits evenly and good players open on the healer, and
     * Rogue/Mage/Druid lost Kidney Shot. So:
     *  - a spell seen in enough goes is listed if players mostly put it on the target, or if they
     *    are split (verdict()); a split spell can be in the healer lock too, marked both ways;
     *  - a spell too rarely seen is rounded out from the kit: a damage dealer's stun or silence,
     *    not curated as healer-only, not stealth-only.
     *
     * @return array<int, array{spell: mixed, mi: int, share: ?float, split: bool}>
     */
    private function killTargetControl(array $comp, callable $isHealer, array $lockNames): array
    {
        $out = [];

        foreach ($comp as $mi => $member) {
            foreach ($member['entries'] as $e) {
                $spell = $e['spell'];
                $name = $spell->display_name;

                if (! ($e['isSelected'] ?? true) || ! in_array($e['drCategory'], ['Stun', 'Silence'], true)
                    || (in_array($name, $lockNames, true) && $this->verdict($name, $spell) !== 'split')
                    || isset($out[$name]) || $spell->requires_stealth) {
                    continue;
                }

                $verdict = $this->verdict($name, $spell);

                // A healer's stuns are its part of the healer lock, not ones to keep for the kill.
                if ($isHealer($mi)) {
                    continue;
                }

                if ($verdict === 'target' || $verdict === 'split') {
                    $out[$name] = ['spell' => $spell, 'mi' => $mi, 'share' => $this->placement($name)['vote'], 'split' => $verdict === 'split'];
                } elseif ($verdict === null && $spell->chain_target !== 'healer') {
                    $out[$name] = ['spell' => $spell, 'mi' => $mi, 'share' => null, 'split' => false];
                }
            }
        }

        // A kit spell whose aura is logged under a longer name ("Garrote" as "Garrote - Silence")
        // is the same button: keep the one seen in play.
        $seen = collect($out)->filter(fn ($k) => $k['share'] !== null)->keys();

        return collect($out)
            ->reject(fn ($k, $name) => $k['share'] === null && $seen->contains(fn ($s) => str_starts_with($s, $name.' ')))
            ->sortByDesc(fn ($k) => $k['share'] ?? -1)->values()->all();
    }

    /** Where a control spell lands in real goes, or null when it is too rarely seen to say. */
    private function placement(string $name): ?array
    {
        $seen = $this->goCooldowns()['control'][$name] ?? null;

        return $seen && $seen['healer'] + $seen['target'] >= self::MIN_PLACEMENTS && ($seen['voters'] ?? 0) >= self::MIN_VOTERS
            ? $seen : null;
    }

    /**
     * 'target', 'split', 'healer', or null when too rarely seen. Read from the per-player vote
     * (wow:go-cooldowns), not the per-cast count: per cast, whoever plays most decides it, and
     * Rastic's Maim on the healer (108 to 36) outvoted Crawlordx's on the target (64 to 22).
     * Counted per player, Maim is split (0.50), Kidney Shot goes on the target (0.62), Hammer of
     * Justice on the healer (0.17).
     */
    private function verdict(string $name, $spell = null): ?string
    {
        $vote = $this->placement($name)['vote'] ?? null;

        $verdict = match (true) {
            $vote === null => null,
            $vote >= self::TARGET_VOTE => 'target',
            $vote >= self::SPLIT_VOTE => 'split',
            default => 'healer',
        };

        // Only a stun or silence holds through damage, so only those can belong to the kill target.
        if ($verdict !== null && $spell && ! in_array($spell->dr_category, ['Stun', 'Silence'], true)) {
            return 'healer';
        }

        // A split is settled by range (Chriso, 2026-10-05): a melee player is already standing
        // on the kill target, so a melee stun goes there (Maim, Kidney Shot), and a ranged one
        // reaches their healer from wherever you are (Binding Shot). Unknown range stays split.
        if ($verdict === 'split' && ($melee = $this->isMelee($spell)) !== null) {
            return $melee ? 'target' : 'healer';
        }

        return $verdict;
    }

    private function onKillTarget(string $name, $spell = null): bool
    {
        return $this->verdict($name, $spell) === 'target';
    }

    /** Same yardstick as CcFormulaService::isMeleeRange(): 10 yards or less. Null when unknown. */
    private function isMelee($spell): ?bool
    {
        if (! $spell || ! preg_match('/(\d+)/', (string) $spell->range_yards, $m)) {
            return null;
        }

        return (int) $m[1] <= self::MELEE_YARDS;
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
                'mi' => $synergies['owner_map'][$spell->id] ?? null,
                'cooldown' => $synergies['cooldown_by_id'][$spell->id] ?? null,
            ])
            ->values()
            ->all();
    }
}
