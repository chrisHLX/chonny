<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The public statement of this project's arena model, parsed out of `data/brain/brain.md`.
 *
 * WHY A FILE AND NOT A DB ROW. This document is the thing every Claude-drafted guide is written
 * from, so a change to it changes every guide downstream. That makes it code, not content: it
 * belongs in a diff, it belongs in a deploy, and it must be identical on every environment. The
 * same reasoning as `guides:author` reading committed JSON drafts rather than a REPL.
 *
 * STABLE SECTION IDS ARE THE WHOLE POINT. Reader comments anchor to a section's id, so ids are
 * written EXPLICITLY in the markdown (`## Heading {#answer-pool}`) rather than slugged from the
 * heading text. Renaming a heading is routine; orphaning every comment under it is not. If a
 * heading has no explicit id the slug is used as a fallback, but new sections should always
 * declare one.
 *
 * The tier line (`::tier observed::`) is the confidence marker from `arena-structure.md`, carried
 * through to the reader on purpose — a model that states a hypothesis in the same voice as an
 * observation is the exact failure the framework file was rewritten to avoid.
 */
class BrainDocument
{
    public const PATH = 'data/brain/brain.md';

    /**
     * Cache key carries a signature of the file itself, so an edit publishes without any manual
     * bump. Deliberately NOT keyed on the global spell cache version — this document has nothing
     * to do with spell data, and bumping that counter to publish a prose change would invalidate
     * all 40 precomputed spec kits for nothing (see CLAUDE.md, caching rule 17).
     */
    public static function sections(): array
    {
        $path = base_path(self::PATH);

        if (! is_file($path)) {
            return [];
        }

        return Cache::remember(
            'brain_doc:'.md5_file($path),
            now()->addDay(),
            fn () => self::parse(file_get_contents($path))
        );
    }

    public static function meta(): array
    {
        $path = base_path(self::PATH);

        if (! is_file($path)) {
            return ['title' => 'The MindCollector Brain', 'subtitle' => null, 'updated' => null];
        }

        return Cache::remember(
            'brain_meta:'.md5_file($path),
            now()->addDay(),
            fn () => self::parseFrontMatter(file_get_contents($path))
        );
    }

    private static function parseFrontMatter(string $raw): array
    {
        $meta = ['title' => 'The MindCollector Brain', 'subtitle' => null, 'updated' => null];

        if (! preg_match('/\A---\R(.*?)\R---\R/s', $raw, $m)) {
            return $meta;
        }

        foreach (preg_split('/\R/', $m[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                $meta[$kv[1]] = trim($kv[2]);
            }
        }

        return $meta;
    }

    /**
     * Split on `## ` headings. Everything before the first heading is discarded along with the
     * front matter — the document is a list of sections and nothing else, because a comment has
     * to have a section to hang from.
     */
    private static function parse(string $raw): array
    {
        $raw = preg_replace('/\A---\R.*?\R---\R/s', '', $raw);

        $parts = preg_split('/^## /m', $raw);
        array_shift($parts);

        $sections = [];

        foreach ($parts as $part) {
            $lines = preg_split('/\R/', $part);
            $heading = trim(array_shift($lines) ?? '');

            if ($heading === '') {
                continue;
            }

            $id = null;
            if (preg_match('/\{#([a-z0-9\-]+)\}\s*$/i', $heading, $m)) {
                $id = strtolower($m[1]);
                $heading = trim(preg_replace('/\{#[a-z0-9\-]+\}\s*$/i', '', $heading));
            }

            $tier = null;
            $body = [];
            foreach ($lines as $line) {
                if ($tier === null && preg_match('/^::tier\s+([a-z]+)::\s*$/i', trim($line), $m)) {
                    $tier = strtolower($m[1]);

                    continue;
                }
                $body[] = $line;
            }

            $sections[] = [
                'id' => $id ?: Str::slug($heading),
                'heading' => $heading,
                'tier' => $tier,
                'html' => Str::markdown(trim(implode("\n", $body))),
            ];
        }

        return $sections;
    }

    /**
     * Badge presentation for a tier. `open` means "this section is a question, not a claim" and
     * gets no confidence colour at all — it is not a weaker observation, it is a different kind
     * of thing.
     */
    public static function tierLabel(?string $tier): ?array
    {
        return match ($tier) {
            'observed' => ['label' => 'Observed', 'class' => 'badge-green',
                'title' => 'A top player said this about real games, or our own player found it in his.'],
            'derived' => ['label' => 'Derived', 'class' => 'badge-blue',
                'title' => 'Follows from game mechanics or data on this site. You can check it.'],
            'hypothesis' => ['label' => 'Hypothesis', 'class' => 'badge-amber',
                'title' => 'Reasoned and plausible, but untested. We might be wrong.'],
            'open' => null,
            default => null,
        };
    }
}
