<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Support\Facades\File;

/**
 * Finds abilities by their CURATED PROPERTIES, for drafting.
 *
 * The question this answers is "which abilities have property X, and who can press them" — the
 * first step in going from a strategic idea to a sequence that could be an instance of it.
 *
 * IT DOES NOT SEARCH DESCRIPTIONS, AND THAT IS THE WHOLE POINT. Asked "what separates players"
 * against `spells.description`, a text search returned 215 rows of which roughly 60% were wrong:
 * Divine Shield, Tranquility, Ultimate Penitence and Death's Advance all matched "knockback"
 * because they are IMMUNE to it. Prose cannot carry polarity — "causes a knockback" and "immune
 * to knockbacks" contain the same word. The same search also produced homonyms (Leg Sweep
 * "knocks down", which is a stun) and flavour text (Lightning Lasso "grips the target", which is
 * a channel). The curated `dr_category = Knockback` answered the identical question with 10 rows
 * and no false positives.
 *
 * So every filter here reads a field somebody curated. A property with no field is not
 * searchable, and {@see unsupported()} says so by name rather than falling back to text and
 * quietly returning nonsense.
 *
 * IT READS THE MATCHUP PROFILES, NOT `spells` DIRECTLY. That matters for two reasons the raw
 * table cannot solve:
 *
 *   - **Duplicate copies.** One visible ability is often several internal spell_id rows
 *     (CLAUDE.md rule 3). A raw query returned `Abomination Limb` seven times and `Charge` seven
 *     times. Worse, a name lookup picks an arbitrary copy: during the investigation that led to
 *     this class, looking up "Demolish" by name returned a copy with an empty immunity list and
 *     nearly produced a bug report against the profiles, which were right.
 *   - **Abilities nobody plays.** Mighty Ox Kick is a curated Knockback in the database and the
 *     project's own player has never seen it taken. It appears in ZERO profiles, because a
 *     profile only carries what a spec's real build selects. That exclusion is free here.
 *
 * The profiles supply who-can-press-what; the database supplies the properties, joined on the
 * external spell id.
 */
class AbilityFinder
{
    /**
     * Filters, and the curated field each one reads. Anything not on this list is unsupported
     * by construction rather than by omission.
     */
    public const FILTERS = [
        'dr' => 'spells.dr_category',
        'mechanic' => 'spells.mechanic',
        'category' => 'spells.category',
        'cast' => 'spells.cast_type',
        'while-cc' => 'spells.usable_while_cc',
        'immune-to' => 'spells.grants_cc_immunity',
        'school-immunity' => 'spells.grants_school_immunity',
        'peel' => 'spells.is_peel',
        'interrupt' => 'spells.is_interrupt',
        'mobility' => 'spells.is_mobility',
        'stealth' => 'spells.requires_stealth',
        'chain-target' => 'spells.chain_target',
        'hard-cc' => 'MatchupProfileService::HARD_CONTROL_CATEGORIES',
        'offensive' => 'the profile\'s arena-log-verified offensive list',
        'defensive' => 'the profile\'s arena-log-verified defensive list',
        'max-cd' => 'the talent-resolved cooldown',
        'min-cd' => 'the talent-resolved cooldown',
        'charges' => 'the talent-resolved charges',
    ];

    /**
     * Properties people reach for that have NO curated field. Named so a caller is told the
     * honest answer instead of being handed a text search — these are the gaps recorded in
     * knowledge-gaps.md and arena-structure.md Part 19.6.
     */
    public const UNSUPPORTED = [
        'dispel' => 'Nothing records what a spell removes. Purge and Remove Corruption are both just "Utility".',
        'freedom' => 'Nothing records that an ability frees an ally from roots — Master\'s Call is only "Mobility".',
        'pull' => 'Only Knockback is curated. "Pulls toward you" has no field; Gorefiend\'s Grasp is tagged Knockback.',
        'wall' => 'Line-of-sight and area denial have no field.',
        'targets' => 'Nothing models who a spell may be cast on. This is why Banish and Shackle Horror shipped as control on players.',
        'external' => 'Nothing records whether a defensive can be cast on a teammate.',
    ];

    /** @var array<int, array>|null */
    private ?array $rows = null;

    /**
     * False when the property join could not run. The profiles alone answer "who can press
     * what", so the list is still usable; every curated PROPERTY is null, which would make a
     * property filter return nothing. The caller is told rather than left to conclude the game
     * has no stuns.
     */
    private bool $propertiesAvailable = true;

    public function propertiesAvailable(): bool
    {
        return $this->propertiesAvailable;
    }

    /**
     * Every pressable ability in every profile, joined to its curated properties.
     *
     * @return array<int, array>
     */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $byExternalId = [];

        foreach (File::glob(base_path('data/matchup-profiles/*/*.json')) as $path) {
            $profile = json_decode(File::get($path), true);

            if (! is_array($profile)) {
                continue;
            }

            $spec = [
                'ref' => $profile['class'].'/'.$profile['spec'],
                'label' => $profile['specName'].' '.$profile['className'],
                'class' => $profile['class'],
            ];

            foreach (['control', 'offensive', 'answers', 'mobility', 'interrupts'] as $bucket) {
                foreach ($profile[$bucket] ?? [] as $entry) {
                    $id = (int) ($entry['spellId'] ?? 0);

                    if ($id === 0) {
                        continue;
                    }

                    $byExternalId[$id] ??= [
                        'spellId' => $id,
                        'name' => $entry['name'],
                        'icon' => $entry['icon'] ?? null,
                        'specs' => [],
                        'buckets' => [],
                        // Cooldown and charges are talent-resolved and can differ per spec, so
                        // the lowest is kept — "how fast can anyone bring this" is the question
                        // a drafting query is asking.
                        'cooldown' => $entry['cooldown'] ?? null,
                        'charges' => $entry['charges'] ?? null,
                        'drCategory' => $entry['drCategory'] ?? null,
                        'duration' => $entry['duration'] ?? null,
                    ];

                    $row = &$byExternalId[$id];
                    $row['specs'][$spec['ref']] = $spec['label'];
                    $row['buckets'][$bucket] = true;

                    if (($entry['cooldown'] ?? null) !== null
                        && ($row['cooldown'] === null || $entry['cooldown'] < $row['cooldown'])) {
                        $row['cooldown'] = $entry['cooldown'];
                    }

                    $row['drCategory'] ??= $entry['drCategory'] ?? null;
                    unset($row);
                }
            }
        }

        if ($byExternalId === []) {
            return $this->rows = [];
        }

        try {
            $properties = Spell::where('patch_id', Patch::where('is_current', true)->value('id'))
                ->whereIn('spell_id', array_keys($byExternalId))
                ->get()
                ->keyBy('spell_id');
        } catch (\Throwable) {
            // No schema, or mid-import. The profile half is real data on disk and stands on its
            // own, so degrade to it rather than failing the whole query.
            $this->propertiesAvailable = false;
            $properties = collect();
        }

        foreach ($byExternalId as $id => $row) {
            $spell = $properties->get($id);

            $byExternalId[$id] += [
                'mechanic' => $spell?->mechanic,
                'category' => $spell?->category,
                'castType' => $spell?->cast_type,
                'usableWhileCc' => $spell?->usable_while_cc,
                'ccImmunity' => $this->list($spell?->grants_cc_immunity),
                // An immunity is very often gated behind a PvP talent — base Fade grants none
                // at all, and only grants one with Phase Shift. Showing the list without the
                // condition would have a drafter build a sequence on an immunity the character
                // may not have, which is the precise class of confidently-wrong output this
                // tool exists to avoid.
                'ccImmunityNote' => $spell?->cc_immunity_note,
                'schoolImmunity' => $spell?->grants_school_immunity,
                'isPeel' => (bool) ($spell?->is_peel),
                'isInterrupt' => (bool) ($spell?->is_interrupt),
                'isMobility' => (bool) ($spell?->is_mobility),
                'requiresStealth' => (bool) ($spell?->requires_stealth),
                'chainTarget' => $spell?->chain_target,
                'description' => trim(preg_replace('/\s+/', ' ', strip_tags((string) $spell?->description))),
            ];
        }

        return $this->rows = array_values($byExternalId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array>
     */
    public function find(array $filters): array
    {
        $rows = $this->all();

        foreach ($filters as $key => $value) {
            if ($value === null || $value === false || $value === []) {
                continue;
            }

            $rows = array_values(array_filter($rows, fn (array $row) => $this->matches($row, $key, $value)));
        }

        usort($rows, function ($a, $b) {
            // Most widely available first: "who has a knockback" is usually asking which specs
            // bring one, and an ability eight specs hold is a different planning fact from one
            // a single spec holds.
            $bySpread = count($b['specs']) <=> count($a['specs']);

            return $bySpread !== 0 ? $bySpread : strcmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /** @param  array<int, string>  $requested */
    public function unsupported(array $requested): array
    {
        $hits = [];

        foreach ($requested as $key) {
            if (isset(self::UNSUPPORTED[$key])) {
                $hits[$key] = self::UNSUPPORTED[$key];
            }
        }

        return $hits;
    }

    private function matches(array $row, string $key, mixed $value): bool
    {
        $anyOf = fn (?string $field) => $field !== null
            && array_filter(
                is_array($value) ? $value : explode(',', (string) $value),
                fn ($v) => strcasecmp(trim($v), $field) === 0
            ) !== [];

        return match ($key) {
            'dr' => $anyOf($row['drCategory']),
            'mechanic' => $anyOf($row['mechanic']),
            'category' => $anyOf($row['category']),
            'cast' => $anyOf($row['castType']),
            'chain-target' => $anyOf($row['chainTarget']),
            'hard-cc' => in_array($row['drCategory'], MatchupProfileService::HARD_CONTROL_CATEGORIES, true),
            'while-cc' => $this->contains($row['usableWhileCc'], (string) $value),
            'immune-to' => $this->listContains($row['ccImmunity'], (string) $value),
            'school-immunity' => $row['schoolImmunity'] !== null && $row['schoolImmunity'] !== '',
            'peel' => $row['isPeel'],
            'interrupt' => $row['isInterrupt'],
            'mobility' => $row['isMobility'],
            'stealth' => $row['requiresStealth'],
            'offensive' => isset($row['buckets']['offensive']),
            'defensive' => isset($row['buckets']['answers']),
            'charges' => ($row['charges'] ?? 0) > 1,
            'max-cd' => $row['cooldown'] !== null && $row['cooldown'] <= (float) $value,
            'min-cd' => $row['cooldown'] !== null && $row['cooldown'] >= (float) $value,
            'spec' => isset($row['specs'][$value]),
            'class' => array_filter(array_keys($row['specs']), fn ($r) => str_starts_with($r, $value.'/')) !== [],
            default => true,
        };
    }

    private function contains(?string $haystack, string $needle): bool
    {
        if ($haystack === null) {
            return false;
        }

        foreach (explode(',', $haystack) as $part) {
            if (strcasecmp(trim($part), trim($needle)) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $list */
    private function listContains(array $list, string $needle): bool
    {
        foreach ($list as $item) {
            if (stripos($item, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * `grants_cc_immunity` is a JSON ARRAY of mechanic names, not a boolean — Berserker Shout
     * holds ["Fear"], Demolish holds ["Stun", ...]. Worth knowing, because `where(column, true)`
     * silently matches nothing and makes the column look empty.
     *
     * @return array<int, string>
     */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * What each curated field actually contains, with counts — so somebody can see which
     * strategic ideas are expressible before trying to express one.
     *
     * @return array<string, array<string, int>>
     */
    public function vocabulary(): array
    {
        $out = [];

        foreach ($this->all() as $row) {
            foreach (['drCategory' => 'dr', 'mechanic' => 'mechanic', 'category' => 'category', 'castType' => 'cast', 'chainTarget' => 'chain-target'] as $field => $label) {
                if (! empty($row[$field])) {
                    $out[$label][$row[$field]] = ($out[$label][$row[$field]] ?? 0) + 1;
                }
            }

            foreach ($row['ccImmunity'] as $mechanic) {
                $out['immune-to'][$mechanic] = ($out['immune-to'][$mechanic] ?? 0) + 1;
            }

            foreach (['isPeel' => 'peel', 'isInterrupt' => 'interrupt', 'isMobility' => 'mobility', 'requiresStealth' => 'stealth'] as $flag => $label) {
                if ($row[$flag]) {
                    $out['flags'][$label] = ($out['flags'][$label] ?? 0) + 1;
                }
            }
        }

        foreach ($out as $label => $values) {
            arsort($out[$label]);
        }

        return $out;
    }
}
