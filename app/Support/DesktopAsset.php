<?php

namespace App\Support;

/**
 * Where the desktop pages (game cards, Improve, Comps) point for an image from the public disk.
 *
 * The same pages are drawn for two readers. The desktop app opens them as files, so an icon is a
 * file:/// path to this machine's storage. The website serves them (`/wow/coach`), where the path
 * must be host-relative (`/storage/...`, the Assets trap in CLAUDE.md). `wow:game-cards --web`
 * switches to the second.
 */
class DesktopAsset
{
    public static function url(string $relative): string
    {
        if (config('desktop.web_assets')) {
            return '/storage/'.ltrim($relative, '/');
        }

        return 'file:///'.str_replace('\\', '/', storage_path('app/public/'.$relative));
    }

    public static function icon(?string $iconName): ?string
    {
        return $iconName ? self::url('spell-icons/'.$iconName) : null;
    }
}
