<?php

namespace App\Quiz\Sc2;

use App\Quiz\QuizQuestion;
use Random\Randomizer;

/**
 * Builds SC2 quiz questions for one race from its units and buildings (Sc2TechTree).
 *
 * Like WoW's builder, a type returns null for anything it cannot ask without doubt: a unit with
 * no weapon in the data is never asked what it can hit, and "which building do you need first"
 * never offers a wrong answer that is also on the unit's tech path (a Hive is needed for a Brood
 * Lord too, one step back), so exactly one option is right.
 */
class Sc2QuestionBuilder
{
    public const LEVEL_TYPES = [
        1 => ['cost', 'supply_provided'],
        2 => ['supply', 'build_time'],
        3 => ['made_at', 'needs'],
        4 => ['weight', 'targets'],
    ];

    /** Real supply costs wrong answers are picked from. */
    private const SUPPLY_LADDER = [0.5, 1, 2, 3, 4, 6, 8];

    /** Real amounts of supply a building or Overlord gives. */
    private const PROVIDED_LADDER = [6, 8, 10, 15];

    private const MADE_AT = [
        'terran' => ['Command Center', 'Barracks', 'Factory', 'Starport'],
        'protoss' => ['Nexus', 'Gateway', 'Robotics Facility', 'Stargate'],
    ];

    private const TARGETS = ['ground' => 'Ground only', 'air' => 'Air only', 'both' => 'Ground and air'];

    private Randomizer $random;

    /** @var array<string, Sc2Unit> label => unit */
    private array $byLabel = [];

    /** @param  array<int, Sc2Unit>  $units  one race's units and buildings */
    public function __construct(private readonly string $race, private readonly array $units, ?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer;
        foreach ($units as $u) {
            $this->byLabel[$u->label] = $u;
        }
    }

    /** @return array<int, QuizQuestion> */
    public function build(int $level, int $count): array
    {
        $types = self::LEVEL_TYPES[$level] ?? [];
        if ($types === [] || $this->units === []) {
            return [];
        }

        // The types take turns, each walking its own shuffled list, so a level mixes them; a type
        // with nothing left to ask drops out and the others fill the quiz.
        $queues = array_fill_keys($types, $this->shuffle($this->units));
        $questions = [];
        $asked = [];

        while (count($questions) < $count && $queues !== []) {
            foreach (array_keys($queues) as $type) {
                if (count($questions) >= $count) {
                    break;
                }
                $question = null;
                while ($question === null && $queues[$type] !== []) {
                    $unit = array_shift($queues[$type]);
                    if (! isset($asked[$unit->label])) {
                        $question = $this->make($type, $unit);
                    }
                }
                if ($question === null) {
                    unset($queues[$type]);

                    continue;
                }
                $asked[$question->subject['label']] = true;
                $questions[] = $question;
            }
        }

        return $this->shuffle($questions);
    }

    public function make(string $type, Sc2Unit $u): ?QuizQuestion
    {
        return match ($type) {
            'cost' => $this->cost($u),
            'supply_provided' => $this->supplyProvided($u),
            'supply' => $this->supply($u),
            'build_time' => $this->buildTime($u),
            'made_at' => $this->madeAt($u),
            'needs' => $this->needs($u),
            'weight' => $this->weight($u),
            'targets' => $this->targets($u),
            default => null,
        };
    }

    private function cost(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->minerals <= 0) {
            return null;
        }

        // Wrong answers are other real prices of this race, the nearest first, so none looks made up.
        $others = collect($this->units)
            ->reject(fn (Sc2Unit $o) => $o->cost() === $u->cost() || $o->minerals <= 0)
            ->unique(fn (Sc2Unit $o) => $o->cost())
            ->sortBy(fn (Sc2Unit $o) => abs($o->minerals - $u->minerals) + abs($o->gas - $u->gas))
            ->take(6)->map->cost()->values()->all();
        if (count($others) < 3) {
            return null;
        }

        [$prompt, $explanation] = match (true) {
            $u->morphsFrom !== null => [
                "What does it cost to morph {$this->a($u->morphsFrom)} into {$this->a($u->label)}?",
                "Morphing {$this->a($u->morphsFrom)} into {$this->a($u->label)} costs {$u->costWords()}, on top of the {$u->morphsFrom}.",
            ],
            $u->fromDrone => [
                "What does {$this->a($u->label)} cost, on top of the Drone that builds it?",
                "{$this->capA($u->label)} costs {$u->costWords()}, and the Drone that builds it becomes the building.",
            ],
            default => ["What does {$this->a($u->label)} cost?", "{$this->capA($u->label)} costs {$u->costWords()}."],
        };

        return $this->question('cost', $u, $prompt.' (minerals / gas)', $u->cost(), $this->pick($others, 3), $explanation);
    }

    private function supplyProvided(Sc2Unit $u): ?QuizQuestion
    {
        // Morphs give the same supply as what they came from (an Orbital Command is still a Command
        // Center's 15), so only the first step is asked: two questions a race, not four alike.
        if ($u->supplyProvided === null || $u->morphsFrom !== null) {
            return null;
        }

        $wrong = $this->nearest(self::PROVIDED_LADDER, $u->supplyProvided);

        return $this->question('supply_provided', $u, "How much supply does {$this->a($u->label)} give you?",
            (string) $u->supplyProvided, array_map('strval', $wrong),
            "{$this->capA($u->label)} adds {$u->supplyProvided} to your supply cap.");
    }

    private function supply(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->supply === null) {
            return null;
        }

        $label = $this->number($u->supply);
        $wrong = array_map(fn ($s) => $this->number($s), $this->nearest(self::SUPPLY_LADDER, $u->supply));
        $explanation = "{$this->capA($u->label)} takes {$label} supply.".($u->supply === 0.5 ? ' A Larva makes two Zerglings, so a pair is 1.' : '');
        if ($u->label === 'Baneling') {
            $explanation = 'A Baneling takes 0.5 supply, the same as the Zergling it came from.';
        }

        return $this->question('supply', $u, "How much supply does {$this->a($u->label)} take?", $label, $wrong, $explanation);
    }

    private function buildTime(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->seconds === null || $u->seconds < 5) {
            return null;
        }

        // Real times from this race, spread at least 3 seconds from the answer and from each other.
        $pool = collect($this->units)->pluck('seconds')->filter(fn ($s) => $s !== null && $s >= 5 && abs($s - $u->seconds) >= 3)
            ->unique()->sortBy(fn ($s) => abs($s - $u->seconds))->values()->all();
        $wrong = [];
        foreach ($this->shuffle(array_slice($pool, 0, 8)) as $s) {
            if (collect($wrong)->every(fn ($w) => abs($w - $s) >= 3)) {
                $wrong[] = $s;
            }
            if (count($wrong) === 3) {
                break;
            }
        }
        if (count($wrong) < 3) {
            return null;
        }

        $verb = $u->morphsFrom ? 'morph' : ($u->structure ? 'build' : 'make');

        return $this->question('build_time', $u, "How long does {$this->a($u->label)} take to {$verb}?",
            "{$u->seconds} seconds", array_map(fn ($s) => "{$s} seconds", $wrong),
            "{$this->capA($u->label)} takes {$u->seconds} seconds at Faster speed, the speed ladder games use.");
    }

    private function madeAt(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->structure || ($u->madeAt === null && $u->morphsFrom === null)) {
            return null;
        }

        if ($this->race === 'zerg') {
            $answerFor = fn (Sc2Unit $x) => match (true) {
                $x->morphsFrom !== null => "Morphed from {$this->a($x->morphsFrom)}",
                $x->madeAt === 'Larva' => 'From a Larva',
                default => "Queued at {$this->a($x->madeAt)}",
            };
            $correct = $answerFor($u);
            $pool = collect($this->units)->reject->structure->map($answerFor)->unique()
                ->reject(fn (string $a) => $a === "Morphed from {$this->a($u->label)}")->values()->all();
        } else {
            $pool = self::MADE_AT[$this->race] ?? [];
            $correct = $u->madeAt;
        }

        if ($correct === null || ! in_array($correct, $pool, true)) {
            return null;
        }

        $explanation = match (true) {
            $u->morphsFrom !== null => "{$this->capA($u->label)} is morphed from {$this->a($u->morphsFrom)}".($u->needs ? ", once you have {$this->a($u->needs)}." : '.'),
            $u->madeAt === 'Larva' => "{$this->capA($u->label)} hatches from a Larva".($u->needs ? ", once you have {$this->a($u->needs)}." : '.'),
            $u->label === 'Queen' => 'A Queen is queued at a Hatchery, Lair or Hive, not made from Larva.',
            default => "{$this->capA($u->label)} is made at {$this->a($u->madeAt)}".($u->needsAddon ? ' with a Tech Lab.' : '.'),
        };

        return $this->question('made_at', $u, "Where does {$this->a($u->label)} come from?", $correct,
            $this->pick(array_values(array_diff($pool, [$correct])), 3), $explanation);
    }

    private function needs(Sc2Unit $u): ?QuizQuestion
    {
        $correct = $u->needs ?? ($u->needsAddon ? 'Tech Lab' : null);
        if ($correct === null) {
            return null;
        }

        // Wrong answers: this race's other buildings that are not on the unit's tech path at all.
        $path = $this->techPath($u);
        $pool = collect($this->units)->filter->structure
            ->reject(fn (Sc2Unit $s) => $s->supplyProvided !== null || in_array($s->label, $path, true) || $s->label === $u->label)
            ->reject(fn (Sc2Unit $s) => in_array($s->label, ['Refinery', 'Assimilator', 'Extractor'], true))
            ->pluck('label')->values()->all();
        if (count($pool) < 3) {
            return null;
        }

        $verb = $u->morphsFrom ? 'morph' : ($u->structure ? 'build' : 'make');
        $also = $u->needs && $u->needsAddon ? " Its {$u->madeAt} also needs a Tech Lab." : '';
        $explanation = $u->needs
            ? "You need {$this->a($u->needs)} before you can {$verb} {$this->a($u->label)}.{$also}"
            : "{$this->capA($u->label)} needs a Tech Lab on the {$u->madeAt}.";

        return $this->question('needs', $u, "Which building do you need before you can {$verb} {$this->a($u->label)}?", $correct,
            $this->pick($pool, 3), $explanation);
    }

    private function weight(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->structure || $u->worker) {
            return null;
        }

        $correct = $u->weight() ?? 'Neither';
        $attributes = implode(', ', array_diff($u->attributes, ['Structure']));
        $explanation = $u->weight()
            ? "{$this->capA($u->label)} is {$u->weight()}. All its attributes: {$attributes}."
            : "{$this->capA($u->label)} is neither Light nor Armored, so bonus damage against either does not apply to it. Its attributes: {$attributes}.";

        return $this->question('weight', $u, "Is {$this->a($u->label)} Light or Armored?", $correct,
            array_values(array_diff(['Light', 'Armored', 'Neither'], [$correct])), $explanation);
    }

    private function targets(Sc2Unit $u): ?QuizQuestion
    {
        if ($u->targets === null || $u->worker) {
            return null;
        }

        $correct = self::TARGETS[$u->targets];
        $words = ['ground' => 'only ground units', 'air' => 'only air units', 'both' => 'ground and air units'][$u->targets];

        return $this->question('targets', $u, "What can {$this->a($u->label)}'s attack hit?", $correct,
            array_values(array_diff(self::TARGETS, [$correct])), "{$this->capA($u->label)}'s attack hits {$words}.");
    }

    /**
     * Every building on the way to a unit: what it needs, where it is made, what it morphs from,
     * and theirs in turn.
     *
     * @return array<int, string>
     */
    private function techPath(Sc2Unit $u, array $seen = []): array
    {
        foreach ([$u->needs, $u->needsAddon, $u->madeAt, $u->morphsFrom] as $label) {
            if ($label && ! in_array($label, $seen, true)) {
                $seen[] = $label;
                if (isset($this->byLabel[$label])) {
                    $seen = $this->techPath($this->byLabel[$label], $seen);
                }
            }
        }

        return $seen;
    }

    /** @param  array<int, string>  $wrong */
    private function question(string $type, Sc2Unit $u, string $prompt, string $correct, array $wrong, string $explanation): ?QuizQuestion
    {
        $labels = array_values(array_unique([$correct, ...$wrong]));
        if (count($labels) < 3) {
            return null;
        }

        $options = array_map(fn (string $label) => ['key' => substr(md5($label), 0, 8), 'label' => $label, 'icon' => null], $this->shuffle($labels));

        return new QuizQuestion($type, $prompt, ['label' => $u->label, 'icon' => null], $options, substr(md5($correct), 0, 8), $explanation);
    }

    /** The three ladder values nearest the answer. */
    private function nearest(array $ladder, float|int $value): array
    {
        $others = array_values(array_filter($ladder, fn ($v) => (float) $v !== (float) $value));
        usort($others, fn ($a, $b) => abs($a - $value) <=> abs($b - $value));

        return array_slice($others, 0, 3);
    }

    private function pick(array $items, int $n): array
    {
        return array_slice($this->shuffle($items), 0, $n);
    }

    private function shuffle(array $items): array
    {
        return $this->random->shuffleArray($items);
    }

    private function number(float $n): string
    {
        return $n == (int) $n ? (string) (int) $n : (string) $n;
    }

    /** "a Marine", "an Overlord", "an SCV". */
    private function a(string $noun): string
    {
        return (preg_match('/^[AEIOU]/', $noun) || $noun === 'SCV' ? 'an ' : 'a ').$noun;
    }

    private function capA(string $noun): string
    {
        return ucfirst($this->a($noun));
    }
}
