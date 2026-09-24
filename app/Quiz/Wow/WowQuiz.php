<?php

namespace App\Quiz\Wow;

use App\Learning\ConceptCoverage;
use App\Models\Concept;
use App\Models\Specialization;
use App\Quiz\GameQuiz;
use App\Quiz\QuizLevel;

/**
 * WoW's quizzes: one set of levels per spec, built from that spec's live kit.
 *
 * TWO KINDS OF SUBJECT, one engine.
 *
 *  - `spec:{id}` — the levelled class quiz. A path from a new player to Gladiator; later levels
 *    (the enemy's kit, counters, matchup plans from guides) add question types here.
 *  - `concept:{conceptId}:{specId}` — a drill on one learning-platform concept, flavoured by the
 *    spec the player is drilling as. Which question types a concept can ask is ConceptCoverage's
 *    business, not this class's.
 *
 * The concept form is what lets a generated question be scored against a concept **without a
 * `questions` row**, which `system-integration.md` names as the one real piece of work in Layer 1.
 * It turned out to need no schema change at all: a quiz attempt already stores the questions
 * exactly as they were asked, and its `subject` column already carries whatever key the game
 * wants to use.
 *
 * MASTERY IS DELIBERATELY NOT WRITTEN FROM HERE. MasteryService denominates a concept's
 * percentage by the whole authored bank (`$concept->questions()->count()`), so feeding it an
 * unbounded generator would push every player's score down as the game data got richer. A drill's
 * record is read back off the attempts instead — see ConceptDrillRecord.
 */
class WowQuiz implements GameQuiz
{
    public function __construct(private readonly WowAbilityFacts $facts) {}

    public function game(): string
    {
        return 'wow';
    }

    public function levels(): array
    {
        return [
            1 => new QuizLevel(1, 'Know your kit', 'Recognise your abilities and what each one is for.'),
            2 => new QuizLevel(2, 'Your cooldowns', 'Your offensive and defensive cooldowns, and how long they take to come back.'),
            3 => new QuizLevel(3, 'Crowd control', 'Which diminishing returns group your crowd control is in, and what shares it.'),
        ];
    }

    public static function subjectFor(Specialization $spec): string
    {
        return "spec:{$spec->id}";
    }

    public static function drillSubjectFor(Concept $concept, Specialization $spec): string
    {
        return "concept:{$concept->id}:{$spec->id}";
    }

    public function questions(string $subject, int $level, int $count): array
    {
        if (str_starts_with($subject, 'concept:')) {
            return $this->drillQuestions($subject, $count);
        }

        $spec = str_starts_with($subject, 'spec:')
            ? Specialization::with('gameClass')->find((int) substr($subject, 5))
            : null;

        if (! $spec || ! isset($this->levels()[$level])) {
            return [];
        }

        return $this->builder($spec, WowQuestionBuilder::LEVEL_TYPES[$level] ?? [])->build($level, $count);
    }

    /** @return array<int, \App\Quiz\QuizQuestion> */
    private function drillQuestions(string $subject, int $count): array
    {
        [, $conceptId, $specId] = array_pad(explode(':', $subject, 3), 3, null);

        $concept = $conceptId === null ? null : Concept::find((int) $conceptId);
        $spec = $specId === null ? null : Specialization::with('gameClass')->find((int) $specId);

        if (! $concept || ! $spec) {
            return [];
        }

        $types = ConceptCoverage::typesFor($concept->name);

        return $types === [] ? [] : $this->builder($spec, $types)->buildTypes($types, $count);
    }

    /**
     * The two expensive pools are loaded only when a type in the mix actually needs them: the
     * other-class pool is a query across every class, and the CC pool spans the whole game.
     *
     * @param  array<int, string>  $types
     */
    private function builder(Specialization $spec, array $types): WowQuestionBuilder
    {
        return new WowQuestionBuilder(
            specLabel: trim("{$spec->name} {$spec->gameClass?->name}"),
            abilities: $this->facts->specAbilities($spec),
            others: in_array('which_is_yours', $types, true) ? $this->facts->otherClassAbilities($spec) : [],
            ccPool: in_array('shares_dr_with', $types, true) ? $this->facts->ccPool() : [],
        );
    }
}
