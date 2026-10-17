<?php

namespace App\Support;

/**
 * Which of two copies of a built file a page reads: the live one the server rebuilds from every
 * upload, or the one committed from the PC. The newer, by `builtAt`, unless the live copy was built
 * from less than half the games of the committed one.
 *
 * Why the second rule (2026-10-17): after a measure's VERSION went up, the deploy rebuilt the live
 * norms before the archive was re-measured on the server, so they held 24 games instead of 1,088.
 * Being newer, they won, and 30 of the 34 spec guides returned 404 for ten minutes, until the archive
 * bundle was loaded. A copy built from a fraction of the games is a gap, not news.
 *
 * Both files start with `builtAt` and `games`, so this reads a few hundred bytes of each.
 */
class LiveOrCommitted
{
    public static function pick(string $committed, string $live): string
    {
        if (! is_file($live)) {
            return $committed;
        }
        if (! is_file($committed)) {
            return $live;
        }
        $head = function (string $path): array {
            $text = (string) @file_get_contents($path, false, null, 0, 400);

            return [
                preg_match('/"builtAt":\s*"([^"]+)"/', $text, $m) ? $m[1] : '',
                preg_match('/"games":\s*(\d+)/', $text, $g) ? (int) $g[1] : 0,
            ];
        };
        [$liveAt, $liveGames] = $head($live);
        [$committedAt, $committedGames] = $head($committed);

        if ($liveGames * 2 < $committedGames) {
            return $committed;
        }

        return $liveAt > $committedAt ? $live : $committed;
    }
}
