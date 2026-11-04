<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\BattlenetClient;
use App\Models\ArenaRound;
use App\Models\Patch;
use App\Models\Spell;
use App\Support\SharedSpells;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Finds the spells any class presses that the spell data does not hold (trinkets, racials, item
 * effects) and writes data/arena-logs/shared-spells.json with each one's spell id and icon
 * (SharedSpells). Run on the PC that holds the raw archive, then commit the file and the new icons.
 *
 *   php -d memory_limit=3G artisan wow:shared-spells          # list what it would add
 *   php -d memory_limit=3G artisan wow:shared-spells --write  # add new ones and fetch their icons
 *
 * A spell qualifies when players of MIN_CLASSES or more classes pressed it in the stored games and
 * the current patch's spell data has no spell by that name: a class's own spells are one class's,
 * and a few share a name across two. The spell id is the one the raw logs show most for that name.
 *
 * Proposes and fills in ids and icons only. Entries already in the file are never removed, and
 * their hand-filled fields (cooldown, charges) are kept: a cooldown is the domain expert's to give,
 * since the shortest gap between two presses in the logs is no cooldown (Spatial Rift is pressed
 * twice in two seconds as one spell, and the Badge read 31s on 2026-11-04).
 */
class BuildSharedSpells extends Command
{
    protected $signature = 'wow:shared-spells {--write : Add the new spells to the file and fetch their icons}';

    protected $description = 'Trinkets, racials and item effects: spell ids and icons for spells the spell data does not hold';

    private const MIN_CLASSES = 3;

    public function handle(ArenaLogService $archive, BattlenetClient $battlenet): int
    {
        $classes = [];
        $presses = [];
        $matches = [];
        ArenaRound::query()->select(['id', 'match_id', 'payload'])->chunkById(50, function ($rows) use (&$classes, &$presses, &$matches) {
            foreach ($rows as $r) {
                $a = $r->payload['analysis'] ?? [];
                $cls = array_column($a['players'] ?? [], 'classSlug', 'guid');
                $matches[$r->match_id] = true;
                foreach ($a['timeline']['casts'] ?? [] as $guid => $list) {
                    foreach ($list as $c) {
                        if (isset($cls[$guid]) && is_string($c[1] ?? null)) {
                            $classes[$c[1]][$cls[$guid]] = true;
                            $presses[$c[1]] = ($presses[$c[1]] ?? 0) + 1;
                        }
                    }
                }
            }
        });

        $patch = Patch::where('is_current', true)->value('id');
        $known = Spell::where('patch_id', $patch)->whereIn('name', array_keys($classes))->pluck('name')->flip();
        $found = [];
        foreach ($classes as $name => $set) {
            if (count($set) >= self::MIN_CLASSES && ! isset($known[$name])) {
                $found[$name] = [];
            }
        }
        if ($found === []) {
            $this->line('Nothing pressed across classes is missing from the spell data.');

            return self::SUCCESS;
        }

        // Each one's spell id, from the raw logs: SPELL_CAST_SUCCESS,src,"name",flags,flags,dst,"name"|nil,flags,flags,id,"spell"
        $pattern = '/SPELL_CAST_SUCCESS,[^,]+,"[^"]*",[^,]+,[^,]+,[^,]+,(?:"[^"]*"|nil),[^,]+,[^,]+,(\d+),"((?:[^"]|"")*)"/';
        foreach (array_keys($matches) as $m) {
            $path = $archive->rawLogPath($m);
            if (! is_file($path)) {
                continue;
            }
            foreach (gzfile($path) as $line) {
                if (str_contains($line, 'SPELL_CAST_SUCCESS') && preg_match($pattern, $line, $x) && isset($found[$x[2]])) {
                    $found[$x[2]][$x[1]] = ($found[$x[2]][$x[1]] ?? 0) + 1;
                }
            }
        }

        $file = SharedSpells::all();
        $new = 0;
        uksort($found, fn ($a, $b) => $presses[$b] <=> $presses[$a]);
        foreach ($found as $name => $ids) {
            arsort($ids);
            $id = (int) array_key_first($ids);
            $have = $file[$name] ?? null;
            $this->line(sprintf('%-34s %2d classes %5d presses  id %-8s %s', $name, count($classes[$name]), $presses[$name], $id ?: '?',
                $have ? 'in the file'.(SharedSpells::cooldown($name) ? ', cooldown '.SharedSpells::cooldown($name).'s' : ', no cooldown yet') : 'new'));
            if (! $have && $id) {
                $file[$name] = ['spellId' => $id, 'icon' => null, 'cooldown' => null];
                $new++;
            }
        }

        if (! $this->option('write')) {
            $this->info("{$new} new. Run with --write to add them and fetch their icons.");

            return self::SUCCESS;
        }

        // Icons from Blizzard's media API, saved beside every other spell icon (committed with them).
        $fetched = 0;
        foreach ($file as $name => $entry) {
            if (! empty($entry['icon'])) {
                continue;
            }
            $media = $battlenet->gameData('us', '/data/wow/media/spell/'.$entry['spellId']);
            $url = collect($media['assets'] ?? [])->firstWhere('key', 'icon')['value'] ?? null;
            // An item's spell (Gladiator's Badge, a trinket's effect) has no media at Blizzard: Wowhead's
            // tooltip names its icon, and the file still comes from Blizzard's own icon CDN, checked
            // first (the way wow:resolve-wowhead-icons does for class spells, 2026-11-04).
            if (! $url && ($tip = ResolveWowheadIcons::fetchTooltip((int) $entry['spellId'])) && ResolveWowheadIcons::verifyOnCdn($tip['icon'])) {
                $url = ResolveWowheadIcons::CDN_BASE.$tip['icon'].'.jpg';
                $this->line("  {$name}: icon {$tip['icon']} from Wowhead's tooltip for {$entry['spellId']}");
            }
            if (! $url) {
                $this->warn("  no icon from Blizzard or Wowhead for {$name} ({$entry['spellId']})");

                continue;
            }
            $icon = basename(parse_url($url, PHP_URL_PATH));
            $disk = storage_path('app/public/spell-icons/'.$icon);
            if (! is_file($disk)) {
                $bytes = $battlenet->downloadMany([$url])[0] ?? null;
                if ($bytes === null) {
                    $this->warn("  could not download the icon for {$name}");

                    continue;
                }
                File::put($disk, $bytes);
            }
            $file[$name]['icon'] = $icon;
            $fetched++;
        }

        ksort($file);
        File::put(SharedSpells::path(), json_encode([
            '_about' => 'Spells any class presses that the spell data does not hold: trinkets, racials, item effects. Written by wow:shared-spells (ids from the raw logs, icons from Blizzard). cooldown is hand-filled by the domain expert; null means it is not tracked as a button. See App\\Support\\SharedSpells.',
            'spells' => $file,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        SharedSpells::forget();
        $this->info("{$new} added, {$fetched} icon(s) fetched. Commit data/arena-logs/shared-spells.json and the new files in storage/app/public/spell-icons.");

        return self::SUCCESS;
    }
}
