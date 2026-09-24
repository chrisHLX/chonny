<?php

namespace App\Learning;

/**
 * What backs each WoW concept: which parts of the arena model it corresponds to, and which
 * generated question types can ask about it from live data.
 *
 * This is the join between two taxonomies that were written years and several directions apart —
 * the learning platform's concepts (Concept rows under the World of Warcraft subject) and
 * `data/brain/brain.md`. It needed no new tables and no new vocabulary, because the two already
 * line up; see `system-integration.md` §4.
 *
 * MATCHED BY NAME, NOT BY ID. Concept ids are seeded and differ per environment, the same reason
 * `guides:author` resolves abilities by name. A name that no longer exists simply has no
 * coverage, which reads as an honest gap rather than a crash.
 *
 * FOUR OF THE SEVEN CONCEPTS HAVE NO GENERATED QUESTIONS, and that is the point of writing this
 * down rather than inferring it. `types` is empty exactly where the data model holds no field to
 * ask about, and `unbacked` says why in the words a player should be shown. Positioning is the
 * clean case: `brain.md` {#gaps} documents it in more concrete detail than almost anything else
 * on the page, and still nothing in the schema represents where anyone is standing. Observed and
 * queryable are different things, and only the second can be generated.
 */
final class ConceptCoverage
{
    /**
     * @var array<string, array{brain: array<int, string>, types: array<int, string>, unbacked: ?string}>
     */
    private const MAP = [
        'Role Fundamentals' => [
            'brain' => ['answer-pool', 'allocation'],
            'types' => ['which_is_yours', 'ability_role'],
            'unbacked' => null,
        ],
        'Crowd Control' => [
            'brain' => ['chains', 'allocation', 'clock'],
            'types' => ['dr_category', 'shares_dr_with', 'pvp_duration', 'usable_while_cc'],
            'unbacked' => null,
        ],
        'Cooldown Management' => [
            'brain' => ['answer-pool', 'overlap', 'clock', 'timeline'],
            'types' => ['is_offensive_cd', 'is_defensive_cd', 'cooldown_length', 'longest_offensive'],
            'unbacked' => null,
        ],
        'Positioning' => [
            'brain' => ['gaps'],
            'types' => [],
            'unbacked' => 'Nothing in the data represents where anyone is standing, so no question here can be generated or checked. Line of sight, facing and range are mechanics we could ask about one day; none of them is a field today.',
        ],
        'Target Switching' => [
            'brain' => ['answer-pool', 'thresholds'],
            'types' => [],
            'unbacked' => 'Choosing a target means comparing what each enemy can still press. The Matchup Lab computes that for a matchup, not for a question, so this concept waits on the application layer.',
        ],
        'Awareness & Tracking' => [
            'brain' => ['information', 'clock'],
            'types' => [],
            'unbacked' => 'Tracking is about hidden state — their cooldowns, their DR, what they have shown you. The data holds what exists, not what either side currently knows.',
        ],
        'Team Composition' => [
            'brain' => ['intent', 'talents', 'timeline'],
            'types' => [],
            'unbacked' => 'Which side the clock favours is a matchup reading, not a per-spell fact. It belongs to the Matchup Lab rather than to a generated question.',
        ],
    ];

    /** @return array<int, string> the generated question types that can ask about this concept */
    public static function typesFor(string $concept): array
    {
        return self::MAP[$concept]['types'] ?? [];
    }

    /** @return array<int, string> brain.md section ids, the anchors a correction is filed against */
    public static function brainSectionsFor(string $concept): array
    {
        return self::MAP[$concept]['brain'] ?? [];
    }

    /**
     * Why this concept has no generated questions, in words a player is shown. Null when it has some.
     *
     * array_key_exists, not `??`: a mapped concept stores an explicit null here, and `??` reads
     * that as "absent" and hands back the unmapped fallback — so every generable concept would
     * have claimed it was not mapped.
     */
    public static function unbackedReason(string $concept): ?string
    {
        return array_key_exists($concept, self::MAP)
            ? self::MAP[$concept]['unbacked']
            : 'This concept is not mapped to the arena model yet.';
    }

    public static function isGenerable(string $concept): bool
    {
        return self::typesFor($concept) !== [];
    }

    /** @return array<int, string> */
    public static function concepts(): array
    {
        return array_keys(self::MAP);
    }
}
