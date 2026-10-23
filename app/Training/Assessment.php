<?php

namespace App\Training;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The reasoning check: form A before the lessons, form B on different problems later. Each
 * concept has one item per form, set in a different domain, so a higher B score says something
 * about transfer rather than memory of the A items.
 */
class Assessment
{
    /** Form B opens after this many complete lessons, or this many days after form A. */
    public const B_AFTER_LESSONS = 6;

    public const B_AFTER_DAYS = 14;

    private array $items;

    public function __construct(private Curriculum $curriculum, ?string $path = null)
    {
        $this->items = require ($path ?? resource_path('training')).'/assessment.php';
        foreach ($this->items as $form => $items) {
            foreach ($items as $id => $item) {
                [$options, $from] = StableShuffle::apply($item['options'], $id);
                $this->items[$form][$id]['options'] = $options;
                $this->items[$form][$id]['answer'] = array_search($item['answer'], $from, true);
            }
        }
    }

    /** @return array<string, array> items keyed by id */
    public function items(string $form): array
    {
        return $this->items[$form] ?? [];
    }

    /** @return array{done: bool, score: int, total: int, byConcept: array<string, bool>, answers: \Illuminate\Support\Collection, at: ?Carbon} */
    public function result(User $user, string $form): array
    {
        $answers = $user->trainingCheckAnswers()->where('form', $form)->get()->keyBy('item');
        $items = $this->items($form);

        return [
            'done' => count($items) > 0 && $answers->count() >= count($items),
            'score' => $answers->where('correct', true)->count(),
            'total' => count($items),
            'byConcept' => collect($items)->mapWithKeys(fn ($i, $id) => [$i['concept'] => (bool) ($answers[$id]->correct ?? false)])->all(),
            'answers' => $answers,
            'at' => $answers->max('created_at'),
        ];
    }

    public function formBOpen(User $user): bool
    {
        $a = $this->result($user, 'A');
        if (! $a['done']) {
            return false;
        }

        return (new Progress($this->curriculum, $user))->completedCount() >= self::B_AFTER_LESSONS
            || Carbon::parse($a['at'])->diffInDays(now()) >= self::B_AFTER_DAYS;
    }
}
