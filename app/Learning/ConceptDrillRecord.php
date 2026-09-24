<?php

namespace App\Learning;

use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * How a player is doing on a concept's generated questions, read back off their attempts.
 *
 * WHY NOT UserConceptMastery. MasteryService scores a concept as
 * `correct ÷ every question attached to the concept` — the whole authored bank, not the
 * questions the player was actually asked. That is already a strange number to show anyone, and
 * it breaks outright against a generator: there is no fixed denominator to divide by, and
 * materialising generated questions as rows to supply one would push every player's percentage
 * down each time the game data got richer. So generated results are never written there.
 *
 * What is reported instead is a plain window — the last N questions you were actually asked, and
 * how many you got right. It needs no table, because the attempt rows already hold every question
 * exactly as it was asked together with the answer given.
 *
 * It is deliberately not called mastery. `brain.md` {#patience} makes the argument better than a
 * comment can: error rates fall, they do not reach zero, so a tool claiming a skill is *mastered*
 * is claiming something the evidence does not support.
 */
final class ConceptDrillRecord
{
    /** How many recent questions a record looks back over. */
    public const WINDOW = 20;

    public function __construct(
        public readonly int $asked,
        public readonly int $correct,
    ) {}

    public function isEmpty(): bool
    {
        return $this->asked === 0;
    }

    /** Right out of the last {@see WINDOW} asked, as a percentage. Null before anything was asked. */
    public function percentage(): ?int
    {
        return $this->asked === 0 ? null : (int) round($this->correct / $this->asked * 100);
    }

    /**
     * One record per concept id, over every spec the player has drilled that concept as.
     *
     * The concept is what is scored; the spec only flavoured the questions. That is the split
     * CLAUDE.md's player model asks for — context flavours content and never partitions mastery —
     * and it is the reason a player who rerolls keeps what they know about crowd control.
     *
     * @param  array<int, int>  $conceptIds
     * @return array<int, self> keyed by concept id
     */
    public static function forViewer(array $conceptIds, ?User $user, string $sessionId): array
    {
        if ($conceptIds === []) {
            return [];
        }

        $subjects = array_map(fn (int $id) => "concept:{$id}:", $conceptIds);

        $attempts = QuizAttempt::where('game', 'wow')
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as $prefix) {
                    $q->orWhere('subject', 'like', $prefix.'%');
                }
            })
            ->when($user, fn ($q) => $q->where('user_id', $user->id),
                fn ($q) => $q->whereNull('user_id')->where('session_id', $sessionId))
            ->where('answered', '>', 0)
            ->orderByDesc('id')
            ->limit(count($conceptIds) * self::WINDOW)
            ->get(['id', 'subject', 'questions', 'answers']);

        return collect($conceptIds)
            ->mapWithKeys(fn (int $id) => [$id => self::fromAttempts($attempts->filter(
                fn (QuizAttempt $a) => str_starts_with((string) $a->subject, "concept:{$id}:")
            ))])
            ->all();
    }

    /**
     * Newest attempts first, counting answered questions until the window is full.
     *
     * Counted per QUESTION rather than per attempt: a drill can be abandoned half way, and an
     * attempt that asked three questions should weigh less than one that asked eight.
     *
     * @param  Collection<int, QuizAttempt>  $attempts
     */
    private static function fromAttempts(Collection $attempts): self
    {
        $asked = 0;
        $correct = 0;

        foreach ($attempts as $attempt) {
            foreach (($attempt->answers ?? []) as $index => $key) {
                if ($asked >= self::WINDOW) {
                    break 2;
                }

                $question = $attempt->question((int) $index);
                if ($question === null) {
                    continue;
                }

                $asked++;
                $correct += $question->isCorrect((string) $key) ? 1 : 0;
            }
        }

        return new self($asked, $correct);
    }

    /** The subject prefix a drill attempt is stored under. @see \App\Quiz\Wow\WowQuiz::drillSubjectFor() */
    public static function subjectPrefix(int $conceptId): string
    {
        return "concept:{$conceptId}:";
    }
}
