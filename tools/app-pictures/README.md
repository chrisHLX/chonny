# The website's pictures of the app

The slider on the front page and on `/wow/app` (`resources/views/components/app-showcase.blade.php`)
shows real pages the app drew from real games. A real game's page names real players, so every
name is swapped for a label before a picture is taken: "You", "Your Rogue", "Their Evoker" (by spec,
"Their Mistweaver", when a side has two of a class), or the class alone in a Solo Shuffle.

Everything is written to `storage/app/app-pictures/` (gitignored), which holds real names until it is
deleted. Only the finished WebP files in `public/images/app/` are committed.

## Taking them again (after a redesign of the app's pages)

From the repo root, in PowerShell:

```powershell
php -d memory_limit=3G artisan wow:game-cards --dir="storage/app/app-pictures/pages" --fresh
php -d memory_limit=3G tools/app-pictures/allnames.php       # names.txt: every name in the database
python tools/app-pictures/anon.py storage/app/app-pictures/pages storage/app/app-pictures/anon <card>.html improve-<id>.html comp-<id>.html <shuffle>.html
tools/app-pictures/snap.ps1 -File <card>.html -Name card
tools/app-pictures/snap.ps1 -File <card>.html -Name basics -Tab basics
tools/app-pictures/snap.ps1 -File <card>.html -Name damage -Tab damage
tools/app-pictures/snap.ps1 -File improve-<id>.html -Name improve
tools/app-pictures/snap.ps1 -File comp-<id>.html -Name comp
tools/app-pictures/snap.ps1 -File <shuffle>.html -Name shuffle
python tools/app-pictures/crop.py
Remove-Item -Recurse storage/app/app-pictures
```

**Before committing, two checks, both needed:**

1. `anon.py` prints, per page, every name from `names.txt` still in it. Every list must be empty.
   `names.txt` comes from the database, not the pages, so it catches a name the page's own rosters
   missed (the first version read only the "Them" / "Your team" rosters and let a whole Solo Shuffle
   lobby through; this check found it).
2. Look at every picture. A word in `names.txt` can be a real word (Beast, Freedom, a pet called Boar):
   add it to `STOP` in `anon.py` rather than let it become "Player Mastery Hunter".

On 2026-10-12 the pictures came from the 3v3 win `670ab495f9a3b601a5e0c13e8757e17f`, `improve-2140eee348`, `comp-1990193cc4` and the Solo Shuffle
`8cbf035fbbfd1008106dcc135273dca1`. The crops are in `crop.py`. Headless Edge will not render narrower
than about 490px, so check the slider at phone width in a 390px iframe served from the same site.
