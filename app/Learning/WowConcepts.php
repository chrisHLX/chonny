<?php

namespace App\Learning;

use App\Models\Concept;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The WoW subject's concepts, in the order ConceptCoverage lists them.
 *
 * The subject is matched on a name PREFIX, not on the full seeded name and not on an id. The
 * seeder writes "World of Warcraft: The War Within", so an expansion boundary would silently
 * orphan every drill if the expansion were part of the match; ids differ per environment for the
 * usual reason. Concepts are then matched by name, the same discipline `guides:author` uses for
 * abilities — a renamed concept loses its coverage rather than resolving cleanly to the wrong one.
 */
final class WowConcepts
{
    /** @return Collection<int, Concept> */
    public static function all(): Collection
    {
        $subject = Subject::where('name', 'like', 'World of Warcraft%')->first();

        if (! $subject) {
            return collect();
        }

        $order = array_flip(ConceptCoverage::concepts());

        return Concept::where('subject_id', $subject->id)
            ->get()
            ->filter(fn (Concept $c) => isset($order[$c->name]))
            ->sortBy(fn (Concept $c) => $order[$c->name])
            ->values();
    }

    public static function findBySlug(string $slug): ?Concept
    {
        return self::all()->first(fn (Concept $c) => self::slug($c) === $slug);
    }

    public static function slug(Concept $concept): string
    {
        return Str::slug($concept->name);
    }
}
