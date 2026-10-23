<?php

namespace App\Training;

/**
 * Shuffles answer options into an order that is fixed per question (seeded by its id) but varies
 * between questions, so the right answer is not always in the same position.
 */
final class StableShuffle
{
    /**
     * @param  list<mixed>  $items
     * @return array{0: list<mixed>, 1: list<int>} the reordered items, and for each new position the original index
     */
    public static function apply(array $items, string $seed): array
    {
        $order = array_keys($items);
        $state = crc32($seed) ?: 1;
        for ($i = count($order) - 1; $i > 0; $i--) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;   // small LCG: deterministic, no global RNG
            $j = $state % ($i + 1);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }

        return [array_map(fn ($k) => $items[$k], $order), $order];
    }
}
