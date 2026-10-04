<?php

namespace App\Http\Services;

use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentNodeEntry;
use Illuminate\Support\Collection;

/**
 * The cooldown ledger (match-review-operations.md, "Reading defensives: three questions"): how many
 * of a side's damage defensives are back at a given moment, with every cooldown resolved from the
 * player's OWN talents, read from the log's COMBATANT_INFO, never the spell table's base value
 * (rule 34). Fortifying Brew is 360s in the table and 90-120s on the Monks we have measured.
 *
 * What a player COULD press is their spec's matchup profile `answers` (data/matchup-profiles, the
 * default build) plus every defensive they actually pressed in the round. A "big" answer has a
 * cooldown of BIG seconds or more. The Medallion is left out: it breaks CC and reduces no damage.
 *
 * Shared by RoundAnalysisService (stored on every go at sync and upload) and
 * tools/match-review/cdledger.php (the research read that found it mattered, 2026-10-02: their go
 * killed one of us in 12% of 3v3 goes with none of our big answers down, 38% with two or more).
 */
class CooldownLedgerService
{
    /** A cooldown this long or longer makes an answer "big". */
    public const BIG = 90;

    public const MEDALLION = "Gladiator's Medallion";

    private ?int $patchId = null;

    /** @var array<string, array{selected: Collection, ranks: Collection, key: string}> */
    private array $builds = [];

    /** @var array<string, array{cd: float, charges: int, base: ?float}|null> */
    private array $cooldowns = [];

    /** @var array<string, array<string, mixed>> profile by class/spec */
    private array $answers = [];

    public function __construct(private ArenaLogService $arena, private ModuleSpellReferenceService $refs) {}

    /**
     * A player's talent selection, from COMBATANT_INFO, as the internal spell ids and ranks that
     * effectiveCooldown() gates on (rule 2: talent_node_entries.spell_id is an internal id).
     *
     * @param  array{talents?: array, pvpTalentIds?: array}  $combatant
     * @return array{selected: Collection, ranks: Collection, key: string}
     */
    public function build(array $combatant, Specialization $spec): array
    {
        $key = $spec->id.':'.md5(json_encode($combatant['talents'] ?? []).json_encode($combatant['pvpTalentIds'] ?? []));

        if (isset($this->builds[$key])) {
            return $this->builds[$key];
        }

        $resolved = $this->arena->resolveCombatantTalents($combatant, $spec->id);
        $entries = TalentNodeEntry::whereIn('id', array_column($resolved['talents'], 'entryId'))->get()->keyBy('id');
        $selected = collect();
        $ranks = collect();
        foreach ($resolved['talents'] as $t) {
            if ($e = $entries->get($t['entryId'])) {
                $selected->push($e->spell_id);
                $ranks[$e->spell_id] = $t['rank'];
            }
        }
        $pvp = array_column($resolved['pvpTalents'], 'spellId');
        if ($pvp) {
            $selected = $selected->merge(Spell::where('patch_id', $this->patchId())->whereIn('spell_id', $pvp)->pluck('id'));
        }

        return $this->builds[$key] = [
            'selected' => $selected->unique()->values(),
            'ranks' => $ranks,
            'key' => $key,
            // By name, for takes(): talents and PvP talents this player has.
            'names' => array_values(array_unique(array_merge(array_column($resolved['talents'], 'name'), array_column($resolved['pvpTalents'], 'name')))),
            'pvp' => array_map(fn ($p) => ['name' => $p['name'], 'spellId' => (int) $p['spellId']], $resolved['pvpTalents']),
        ];
    }

    /** @var array<int, array<string, true>> spec id => every talent and PvP talent name it could take */
    private array $talentNames = [];

    /**
     * Whether this player can press $name: true for a baseline spell, and for a talent or PvP talent
     * only when they took it. A profile is the spec's DEFAULT build, so without this a Druid who
     * took Mighty Bash would be listed with Incapacitating Roar "ready and never pressed".
     *
     * @param  array{names: array<int, string>}  $build
     */
    public function takes(string $name, Specialization $spec, array $build): bool
    {
        $this->talentNames[$spec->id] ??= $this->loadTalentNames($spec);

        return ! isset($this->talentNames[$spec->id][$name]) || in_array($name, $build['names'], true);
    }

    /** @return array<string, true> */
    private function loadTalentNames(Specialization $spec): array
    {
        $trees = \App\Models\TalentTree::where('patch_id', $this->patchId())
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('class_id', $spec->class_id)->where('type', 'class'))
                ->orWhere(fn ($q2) => $q2->where('spec_id', $spec->id)->where('type', 'spec'))
                ->orWhere(fn ($q2) => $q2->where('type', 'hero')->whereHas('specializations', fn ($q3) => $q3->where('specializations.id', $spec->id))))
            ->pluck('id');
        $names = TalentNodeEntry::whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $trees))->with('spell')->get()
            ->map(fn ($e) => $e->spell?->display_name)
            ->merge(\App\Models\PvpTalent::where('spec_id', $spec->id)->with('spell')->get()->map(fn ($p) => $p->spell?->display_name))
            ->filter()->unique();

        return array_fill_keys($names->all(), true);
    }

    /**
     * A spell's cooldown and charges for this build, or null when the data has no cooldown for it.
     * Looked up by name, preferring the pressable copy (rule 3).
     *
     * @param  array{selected: Collection, ranks: Collection, key: string}  $build
     * @return array{cd: float, charges: int, base: ?float}|null
     */
    public function cooldown(string $name, Specialization $spec, array $build): ?array
    {
        if ($name === self::MEDALLION) {
            return ['cd' => 120.0, 'charges' => 1, 'base' => 120.0];
        }

        $key = $build['key'].'|'.$name;
        if (array_key_exists($key, $this->cooldowns)) {
            return $this->cooldowns[$key];
        }

        // `display_name` is an accessor: the column is `name`, sometimes with a "(desc=...)" suffix.
        $spell = Spell::where('patch_id', $this->patchId())
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name', 'like', $name.' (desc=%'))
            ->where(fn ($q) => $q->where('cooldown_seconds', '>', 0)->orWhere('charges', '>', 0))
            ->orderBy('is_passive')->orderBy('not_in_spellbook')->orderByDesc('cooldown_seconds')
            ->first();

        if (! $spell) {
            return $this->cooldowns[$key] = null;
        }

        $gb = new ModuleGameBuild(['class_id' => $spec->class_id, 'specialization_id' => $spec->id]);
        $cd = $this->refs->effectiveCooldown($spell, $gb, $build['selected'], $build['ranks']);
        $ch = $this->refs->effectiveCharges($spell, $gb, $build['selected'], $build['ranks']);
        $seconds = $cd['seconds'] ?? $cd['base_seconds'] ?? null;

        if (! $seconds) {
            return $this->cooldowns[$key] = null;
        }

        return $this->cooldowns[$key] = [
            'cd' => (float) $seconds,
            'charges' => (int) max(1, round($ch['charges'] ?? $ch['value'] ?? 1)),
            'base' => isset($cd['base_seconds']) ? (float) $cd['base_seconds'] : null,
        ];
    }

    /**
     * The defensives a spec's profile lists as its answers (default build), by name.
     *
     * @return array<int, string>
     */
    public function profileAnswers(?string $classSlug, ?string $specSlug): array
    {
        return array_values(array_column($this->profile($classSlug, $specSlug)['answers'] ?? [], 'name'));
    }

    /**
     * A spec's whole matchup profile (answers, control, interrupts, ...), or [] when there is none.
     *
     * @return array<string, mixed>
     */
    public function profile(?string $classSlug, ?string $specSlug): array
    {
        if (! $classSlug || ! $specSlug) {
            return [];
        }
        $key = "{$classSlug}/{$specSlug}";
        if (! isset($this->answers[$key])) {
            $file = base_path("data/matchup-profiles/{$key}.json");
            $this->answers[$key] = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return $this->answers[$key];
    }

    /**
     * Which of a side's answers are back at moment $t.
     *
     * @param  array<int, array{who: string, spell: string, cd: float, charges: int}>  $answers  every answer the side could press
     * @param  array<string, array<string, array<int, float>>>  $presses  who => spell => press times
     * @return array{up: int, all: int, bigDown: int, down: array<int, string>}
     */
    public static function coverage(array $answers, array $presses, float $t): array
    {
        $up = 0;
        $all = 0;
        $bigDown = 0;
        $down = [];

        foreach ($answers as $a) {
            if ($a['spell'] === self::MEDALLION) {
                continue;
            }
            $all++;
            // Charges are modelled as independent: each back one cooldown after its own press. In
            // game a second charge starts only once the first is back, so this overstates "up".
            $recent = array_filter($presses[$a['who']][$a['spell']] ?? [], fn ($p) => $p < $t && $t < $p + $a['cd']);
            if (count($recent) < $a['charges']) {
                $up++;

                continue;
            }
            $down[] = $a['spell'];
            if ($a['cd'] >= self::BIG) {
                $bigDown++;
            }
        }

        return ['up' => $up, 'all' => $all, 'bigDown' => $bigDown, 'down' => $down];
    }

    private function patchId(): ?int
    {
        return $this->patchId ??= Patch::where('is_current', true)->value('id');
    }
}
