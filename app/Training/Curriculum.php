<?php

namespace App\Training;

/**
 * Training's content: subjects, lessons and concepts, read from resources/training.
 *
 * Content lives in PHP array files rather than the database so it is versioned with the code that
 * renders it. Notes and answers refer to lessons by slug and to passages by block id plus quoted
 * text, so editing a lesson does not orphan what learners wrote on it. Answer options are shown in
 * a stable shuffled order per question (StableShuffle), so files can list them in any order.
 */
class Curriculum
{
    /** @var array<string, array> */
    private array $subjects = [];

    /** @var array<string, array> */
    private array $lessons = [];

    /** @var array<string, array> */
    private array $concepts;

    /** @var array<string, array> long-form reading pages (readings.php) */
    private array $readings = [];

    public function __construct(?string $path = null)
    {
        $path ??= resource_path('training');
        $this->concepts = require $path.'/concepts.php';
        foreach (require $path.'/readings.php' as $reading) {
            $this->readings[$reading['slug']] = $reading;
        }

        $n = 0;
        foreach (require $path.'/subjects.php' as $i => $subject) {
            $subject['number'] = $i + 1;
            $subject['lessons'] = [];
            foreach (require $path.'/lessons/'.$subject['slug'].'.php' as $j => $lesson) {
                foreach (['case', 'transfer'] as $ex) {
                    $lesson[$ex]['options'] = StableShuffle::apply($lesson[$ex]['options'], $lesson['slug'].'/'.$ex)[0];
                }
                $lesson['subject'] = $subject['slug'];
                $lesson['number'] = $j + 1;
                $lesson['order'] = $n++;
                $this->lessons[$lesson['slug']] = $lesson;
                $subject['lessons'][] = $lesson['slug'];
            }
            $this->subjects[$subject['slug']] = $subject;
        }
    }

    /** @return array<string, array> */
    public function subjects(): array
    {
        return $this->subjects;
    }

    public function subject(string $slug): ?array
    {
        return $this->subjects[$slug] ?? null;
    }

    /** @return array<string, array> every lesson, in course order */
    public function lessons(): array
    {
        return $this->lessons;
    }

    public function lesson(string $slug): ?array
    {
        return $this->lessons[$slug] ?? null;
    }

    /** @return list<array> */
    public function lessonsFor(string $subject): array
    {
        return array_map(fn ($s) => $this->lessons[$s], $this->subjects[$subject]['lessons'] ?? []);
    }

    public function next(string $slug): ?array
    {
        $order = $this->lessons[$slug]['order'] ?? null;

        return $order === null ? null : (array_values($this->lessons)[$order + 1] ?? null);
    }

    /** @return array<string, array{name: string, definition: string}> */
    public function concepts(): array
    {
        return $this->concepts;
    }

    public function concept(string $slug): ?array
    {
        return $this->concepts[$slug] ?? null;
    }

    /** @return array<string, array> */
    public function readings(): array
    {
        return $this->readings;
    }

    public function reading(string $slug): ?array
    {
        return $this->readings[$slug] ?? null;
    }

    /**
     * Any page a note or a saved answer can belong to: a lesson or a reading. Notes are keyed by
     * this slug, so the notebook can name and link the page whichever kind it is.
     *
     * @return array{kind: string, title: string, url: string}|null
     */
    public function page(string $slug): ?array
    {
        if ($l = $this->lesson($slug)) {
            return ['kind' => 'lesson', 'title' => $l['title'], 'url' => route('training.lesson', $slug)];
        }
        if ($r = $this->reading($slug)) {
            return ['kind' => 'reading', 'title' => $r['title'], 'url' => route('training.reading', $slug)];
        }

        return null;
    }

    /** The multiple-choice question behind a lesson's 'case' or 'transfer' exercise. */
    public function question(array $lesson, string $exercise): ?array
    {
        return match ($exercise) {
            'case' => $lesson['case'] ?? null,
            'transfer' => $lesson['transfer'] ?? null,
            default => null,
        };
    }
}
