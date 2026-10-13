<?php

namespace App\Support;

/**
 * Whether a defensive that sat ready through the go that killed could have saved the player who died
 * (2026-10-13). Reads data/arena-logs/spell-classification/defensive-fit.json: whom each defensive can
 * reach and what it covers, reviewed by hand from the evidence of tools/match-review/defreach.php
 * (where each landed across the archive) and defensivefit.php (the spell data's schools).
 *
 * Three answers, never a verdict on the play:
 *  - fits: it could reach them and covers that damage (all damage, a heal, or the school that did most
 *    of the last ten seconds);
 *  - other: something else it can do there (break crowd control, peel, hide), a judgement the log
 *    cannot make;
 *  - no: it could not reach them, or covers the other school; with the reason.
 * A defensive with no reviewed tag is "other", marked untagged, so nothing unreviewed reads as a fault.
 */
class DefensiveFit
{
    /** A school-only defensive fits when its school did at least this share of the last 10s. */
    private const SCHOOL_SHARE = 40;

    /** @var array<string, array{reach: ?string, covers: ?string}>|null */
    private static ?array $tags = null;

    public static function path(): string
    {
        return base_path('data/arena-logs/spell-classification/defensive-fit.json');
    }

    public static function tags(): array
    {
        if (self::$tags === null) {
            $doc = json_decode((string) @file_get_contents(self::path()), true) ?: [];
            self::$tags = collect($doc['defensives'] ?? [])->keyBy('name')->map(fn ($e) => ['reach' => $e['reach'] ?? null, 'covers' => $e['covers'] ?? null])->all();
        }

        return self::$tags;
    }

    /**
     * @param  array{physical: int, magic: int}|null  $schools  the death's last 10s (version 14); null for older rounds, when only reach is judged
     * @return array{fit: 'fits'|'other'|'no', why: ?string}
     */
    public static function judge(string $spell, bool $ownerDied, ?array $schools): array
    {
        $t = self::tags()[$spell] ?? null;
        if (! $t || ! $t['reach'] || ! $t['covers']) {
            return ['fit' => 'other', 'why' => 'not tagged yet'];
        }
        if ($t['reach'] === 'none' || $t['covers'] === 'none') {
            return ['fit' => 'no', 'why' => 'not a damage answer'];
        }

        // Breaking your own crowd control or hiding helps whoever is dying, so reach is not asked of
        // them: a healer's Medallion is how a teammate gets saved.
        if (in_array($t['covers'], ['cc', 'avoid'], true)) {
            return ['fit' => 'other', 'why' => null];
        }

        $reaches = $ownerDied ? in_array($t['reach'], ['self', 'self-or-other', 'team'], true) : in_array($t['reach'], ['other', 'self-or-other', 'team'], true);
        if (! $reaches) {
            return ['fit' => 'no', 'why' => $ownerDied ? 'only on someone else' : 'only on themselves'];
        }

        return match ($t['covers']) {
            'all', 'heal' => ['fit' => 'fits', 'why' => null],
            'physical', 'magic' => $schools === null || ($schools[$t['covers']] ?? 0) >= self::SCHOOL_SHARE
                ? ['fit' => 'fits', 'why' => null]
                : ['fit' => 'no', 'why' => $t['covers'].' only; '.($schools[$t['covers'] === 'physical' ? 'magic' : 'physical'] ?? 0).'% '.($t['covers'] === 'physical' ? 'magic' : 'physical')],
            default => ['fit' => 'other', 'why' => null],
        };
    }
}
