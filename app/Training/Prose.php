<?php

namespace App\Training;

/**
 * Inline formatting for lesson text: escapes everything, then allows **bold** and *italic*.
 * Lesson files are written by hand; this keeps them readable without allowing raw HTML.
 */
final class Prose
{
    public static function inline(string $text): string
    {
        $s = e($text);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<b class="text-ink font-semibold">$1</b>', $s);

        return preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/s', '<i>$1</i>', $s);
    }
}
