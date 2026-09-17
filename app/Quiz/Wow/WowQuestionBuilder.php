<?php

namespace App\Quiz\Wow;

use App\Quiz\QuizQuestion;
use Random\Randomizer;

/**
 * Builds WoW quiz questions from plain ability facts (see WowAbilityFacts).
 *
 * Every question type only asks something the data can answer without doubt, and returns null
 * when it can't: an ability with two true roles is never asked "what is this used for", and a
 * "longest cooldown" question needs one clear winner. A skipped question costs nothing; a wrong
 * one teaches the player something false.
 */
class WowQuestionBuilder
{
    /** Which question types each level uses, in the order they are mixed. */
    public const LEVEL_TYPES = [
        1 => ['which_is_yours', 'ability_role'],
        2 => ['is_offensive_cd', 'is_defensive_cd', 'cooldown_length', 'longest_offensive'],
        3 => ['dr_category', 'shares_dr_with'],
    ];

    private const ROLE_LABELS = [
        'cc' => 'Crowd control',
        'interrupt' => 'Interrupt',
        'offensive' => 'Offensive cooldown',
        'defensive' => 'Defensive cooldown',
    ];

    /** Real cooldown lengths wrong answers are picked from, so no option looks made up. */
    private const COOLDOWN_LADDER = [10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 300, 600];

    private Randomizer $random;

    /**
     * @param  array<int, WowAbility>  $abilities  the spec's own abilities
     * @param  array<int, WowAbility>  $others  abilities no spec of this class has
     * @param  array<int, WowAbility>  $ccPool  crowd control across the game
     */
    public function __construct(
        private readonly string $specLabel,
        private readonly array $abilities,
        private readonly array $others,
        private readonly array $ccPool,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer;
    }

    /** @return array<int, QuizQuestion> */
    public function build(int $level, int $count): array
    {
        $types = self::LEVEL_TYPES[$level] ?? [];
        if ($types === [] || $this->abilities === []) {
            return [];
        }

        $questions = [];
        $asked = [];
        $abilityNamesUsed = [];

        // Each type walks its own shuffled list of subjects, and the types take turns, so a level
        // mixes its question types. A type that runs out of subjects drops out; a spec with little
        // verified data simply gets a shorter quiz.
        $queues = array_fill_keys($types, $this->shuffle($this->abilities));
        // Abilities already asked about wait here, and are only used once a type has nothing fresh
        // left: a spec with three crowd control abilities still gets both kinds of question on each.
        $deferred = array_fill_keys($types, []);

        while (count($questions) < $count && $queues !== []) {
            foreach (array_keys($queues) as $type) {
                if (count($questions) >= $count) {
                    break;
                }

                $question = null;
                while ($question === null && ($queues[$type] !== [] || $deferred[$type] !== [])) {
                    $fresh = $queues[$type] !== [];
                    $ability = $fresh ? array_shift($queues[$type]) : array_shift($deferred[$type]);

                    if ($fresh && isset($abilityNamesUsed[$ability->name])) {
                        $deferred[$type][] = $ability;

                        continue;
                    }

                    $question = $this->make($type, $ability);
                }

                if ($question === null) {
                    unset($queues[$type]);

                    continue;
                }

                $signature = $type.':'.($question->subject['label'] ?? $question->correctKey);
                if (isset($asked[$signature])) {
                    continue;
                }

                $asked[$signature] = true;
                foreach ($this->abilitiesIn($question) as $name) {
                    $abilityNamesUsed[$name] = true;
                }
                $questions[] = $question;
            }
        }

        return $this->shuffle($questions);
    }

    public function make(string $type, WowAbility $subject): ?QuizQuestion
    {
        return match ($type) {
            'which_is_yours' => $this->whichIsYours($subject),
            'ability_role' => $this->abilityRole($subject),
            'is_offensive_cd' => $this->isCooldown($subject, 'offensive'),
            'is_defensive_cd' => $this->isCooldown($subject, 'defensive'),
            'cooldown_length' => $this->cooldownLength($subject),
            'longest_offensive' => $this->longestOffensive(),
            'dr_category' => $this->drCategory($subject),
            'shares_dr_with' => $this->sharesDrWith($subject),
            default => null,
        };
    }

    private function whichIsYours(WowAbility $correct): ?QuizQuestion
    {
        $wrong = array_slice($this->shuffle($this->others), 0, 3);
        if (count($wrong) < 3) {
            return null;
        }

        return $this->iconQuestion(
            'which_is_yours',
            "Which of these is a {$this->specLabel} ability?",
            null,
            $correct,
            $wrong,
            "{$correct->name} is a {$this->specLabel} ability. ".implode(' ', array_map(
                fn (WowAbility $a) => "{$a->name} belongs to ".($a->className ? "the {$a->className}" : 'another class').'.',
                $wrong,
            )),
        );
    }

    private function abilityRole(WowAbility $ability): ?QuizQuestion
    {
        $roles = array_keys(array_filter([
            'cc' => $ability->drCategory !== null,
            'interrupt' => $ability->interrupt,
            'offensive' => $ability->offensive,
            'defensive' => $ability->defensive,
        ]));

        // An ability with two true roles (a stun that is also an offensive cooldown) has no single
        // right answer, so it is never asked this way.
        if (count($roles) !== 1) {
            return null;
        }

        $role = $roles[0];
        $detail = $role === 'cc' ? " It's ".$this->article($ability->drCategory).'.' : '';

        return new QuizQuestion(
            type: 'ability_role',
            prompt: "What is {$ability->name} mainly used for?",
            subject: ['label' => $ability->name, 'icon' => $ability->iconPath()],
            options: array_map(fn ($key) => ['key' => $key, 'label' => self::ROLE_LABELS[$key], 'icon' => null], array_keys(self::ROLE_LABELS)),
            correctKey: $role,
            explanation: "{$ability->name} is ".($role === 'cc' ? 'crowd control' : $this->article(strtolower(self::ROLE_LABELS[$role]))).'.'.$detail,
        );
    }

    private function isCooldown(WowAbility $correct, string $direction): ?QuizQuestion
    {
        $opposite = $direction === 'offensive' ? 'defensive' : 'offensive';
        if (! $correct->{$direction} || $correct->{$opposite}) {
            return null;
        }

        $wrong = array_slice($this->shuffle(array_filter(
            $this->abilities,
            fn (WowAbility $a) => ! $a->{$direction} && $a->name !== $correct->name,
        )), 0, 3);

        if (count($wrong) < 3) {
            return null;
        }

        return $this->iconQuestion(
            $direction === 'offensive' ? 'is_offensive_cd' : 'is_defensive_cd',
            "Which of these is one of {$this->specLabel}'s {$direction} cooldowns?",
            null,
            $correct,
            $wrong,
            "{$correct->name} is ".$this->article("{$direction} cooldown").$this->cooldownSuffix($correct).'. The others are not.',
        );
    }

    private function cooldownLength(WowAbility $ability): ?QuizQuestion
    {
        if ($ability->cooldown === null || $ability->cooldown < 10 || fmod($ability->cooldown, 1.0) !== 0.0) {
            return null;
        }

        $correct = (int) $ability->cooldown;

        // Wrong answers must be clearly different from the right one and from each other, so the
        // question tests whether the player knows the cooldown rather than their luck between
        // 24 and 25 seconds.
        $apart = fn (int $a, int $b) => abs($a - $b) >= max(10, (int) round(max($a, $b) * 0.25));

        $candidates = array_values(array_filter(self::COOLDOWN_LADDER, fn (int $s) => $apart($s, $correct)));
        usort($candidates, fn (int $a, int $b) => abs($a - $correct) <=> abs($b - $correct));

        $wrong = [];
        foreach ($candidates as $candidate) {
            if (collect($wrong)->every(fn (int $w) => $apart($w, $candidate))) {
                $wrong[] = $candidate;
            }
            if (count($wrong) === 3) {
                break;
            }
        }

        if (count($wrong) < 3) {
            return null;
        }

        $values = $this->shuffle([$correct, ...$wrong]);

        return new QuizQuestion(
            type: 'cooldown_length',
            prompt: "How long is {$ability->name}'s cooldown?",
            subject: ['label' => $ability->name, 'icon' => $ability->iconPath()],
            options: array_map(fn (int $s) => ['key' => (string) $s, 'label' => $this->formatSeconds($s), 'icon' => null], $values),
            correctKey: (string) $correct,
            explanation: "{$ability->name} has a ".$this->formatSeconds($correct).' cooldown with the talents most high-rated players take. Talents can change it.',
        );
    }

    private function longestOffensive(): ?QuizQuestion
    {
        $offensive = array_values(array_filter($this->abilities, fn (WowAbility $a) => $a->offensive && $a->cooldown !== null));
        $picked = array_slice($this->shuffle($offensive), 0, 4);

        if (count($picked) < 3) {
            return null;
        }

        usort($picked, fn (WowAbility $a, WowAbility $b) => $b->cooldown <=> $a->cooldown);

        // Needs one clear winner.
        if ($picked[0]->cooldown === $picked[1]->cooldown) {
            return null;
        }

        $correct = $picked[0];

        return $this->iconQuestion(
            'longest_offensive',
            'Which of these offensive cooldowns has the longest cooldown?',
            null,
            $correct,
            array_slice($picked, 1),
            implode(', ', array_map(fn (WowAbility $a) => $a->name.' '.$this->formatSeconds((int) $a->cooldown), $picked)).'.',
        );
    }

    private function drCategory(WowAbility $ability): ?QuizQuestion
    {
        if ($ability->drCategory === null) {
            return null;
        }

        $wrong = array_slice($this->shuffle(array_values(array_diff(WowAbilityFacts::CC_CATEGORIES, [$ability->drCategory]))), 0, 3);
        $options = $this->shuffle([$ability->drCategory, ...$wrong]);

        return new QuizQuestion(
            type: 'dr_category',
            prompt: "Which diminishing returns group is {$ability->name} in?",
            subject: ['label' => $ability->name, 'icon' => $ability->iconPath()],
            options: array_map(fn (string $c) => ['key' => $c, 'label' => $c, 'icon' => null], $options),
            correctKey: $ability->drCategory,
            explanation: "{$ability->name} is ".$this->article($ability->drCategory).'. Crowd control in the same group shortens the next one: full length, then half, then the target is immune.',
        );
    }

    private function sharesDrWith(WowAbility $ability): ?QuizQuestion
    {
        if ($ability->drCategory === null) {
            return null;
        }

        $same = array_filter($this->ccPool, fn (WowAbility $a) => $a->drCategory === $ability->drCategory && $a->name !== $ability->name);
        $correct = $this->shuffle($same)[0] ?? null;

        $wrong = [];
        foreach ($this->shuffle(array_values(array_diff(WowAbilityFacts::CC_CATEGORIES, [$ability->drCategory]))) as $category) {
            $pick = $this->shuffle(array_filter($this->ccPool, fn (WowAbility $a) => $a->drCategory === $category))[0] ?? null;
            if ($pick) {
                $wrong[] = $pick;
            }
            if (count($wrong) === 3) {
                break;
            }
        }

        if ($correct === null || count($wrong) < 3) {
            return null;
        }

        return $this->iconQuestion(
            'shares_dr_with',
            "Which of these shares diminishing returns with {$ability->name}?",
            ['label' => $ability->name, 'icon' => $ability->iconPath()],
            $correct,
            $wrong,
            "{$ability->name} and {$correct->name} are both {$ability->drCategory}s. ".implode(' ', array_map(
                fn (WowAbility $a) => "{$a->name} is ".$this->article($a->drCategory).'.',
                $wrong,
            )),
        );
    }

    /** @param  array<int, WowAbility>  $wrong */
    private function iconQuestion(string $type, string $prompt, ?array $subject, WowAbility $correct, array $wrong, string $explanation): QuizQuestion
    {
        $options = $this->shuffle([$correct, ...$wrong]);

        return new QuizQuestion(
            type: $type,
            prompt: $prompt,
            subject: $subject,
            options: array_map(fn (WowAbility $a) => ['key' => (string) $a->spellId, 'label' => $a->name, 'icon' => $a->iconPath()], $options),
            correctKey: (string) $correct->spellId,
            explanation: $explanation,
        );
    }

    /**
     * The spec's own abilities a question is about: its subject, or its right answer on an icon
     * question. Used to spread questions across the kit.
     *
     * @return array<int, string>
     */
    private function abilitiesIn(QuizQuestion $question): array
    {
        if ($question->subject !== null) {
            return [$question->subject['label']];
        }

        $correct = collect($question->options)->firstWhere('key', $question->correctKey);

        return $correct ? [$correct['label']] : [];
    }

    /** "a Stun", "an Incapacitate", "an offensive cooldown". */
    private function article(string $noun): string
    {
        return (preg_match('/^[aeiou]/i', $noun) ? 'an ' : 'a ').$noun;
    }

    private function cooldownSuffix(WowAbility $a): string
    {
        return $a->cooldown ? ' ('.$this->formatSeconds((int) $a->cooldown).')' : '';
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds >= 120 && $seconds % 60 === 0) {
            return ($seconds / 60).' min';
        }

        return "{$seconds} sec";
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    private function shuffle(array $items): array
    {
        $items = array_values($items);

        return $items === [] ? [] : $this->random->shuffleArray($items);
    }
}
