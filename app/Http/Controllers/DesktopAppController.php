<?php

namespace App\Http\Controllers;

use App\Models\PageViewEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop app's download (tools/desktop-app), at /wow/coach/app. Unlisted on purpose while it is
 * tested by two players (2026-10-08): nothing links to it, and it needs an account, which a tester
 * needs anyway for the key the app uploads with.
 *
 * The exe is not in git (69 MB, a build artefact). It is published to the server by hand into
 * storage/app/desktop/MindCollector.exe, after `dotnet publish` (tools/desktop-app/README.md).
 */
class DesktopAppController extends Controller
{
    public static function path(): string
    {
        return storage_path('app/desktop/MindCollector.exe');
    }

    public function download(Request $request): Response
    {
        PageViewEvent::log('desktop_app');
        abort_unless(is_file(self::path()), 404, 'The desktop app has not been published to this server yet.');

        return response()->download(self::path(), 'MindCollector.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
