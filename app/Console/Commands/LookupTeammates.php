<?php

namespace App\Console\Commands;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\RealmDirectory;
use App\Jobs\BuildCoachPages;
use App\Models\ArenaRoundPlayer;
use App\Models\BattlenetCharacter;
use Illuminate\Console\Command;

/**
 * Looks up, once, every teammate we hold no profile for, so the Teammates pages can show their
 * rating, exp and titles without ever asking Blizzard themselves (TeammatesService). Paced, and safe
 * to run again: anyone already stored is skipped. Then rebuilds the pages of the players affected.
 */
class LookupTeammates extends Command
{
    protected $signature = 'wow:lookup-teammates
        {--min=2 : Games together before a teammate is worth a lookup}
        {--pause=250 : Milliseconds between lookups}
        {--dry-run : Say how many would be looked up, and look up nobody}';

    protected $description = 'Look up each stored teammate once, so the Teammates pages have their ratings and titles';

    public function handle(BattlenetCharacterSyncService $sync, RealmDirectory $realms): int
    {
        $min = (int) $this->option('min');
        // Not China: Blizzard's API for it can't be reached from here, so each one is only a logged error.
        $rows = ArenaRoundPlayer::whereIn('bracket', ['2v2', '3v3'])->where('side', 'us')->where('region', '!=', 'cn')
            ->get(['user_id', 'match_id', 'name', 'realm', 'realm_key', 'region']);

        $byUser = [];
        foreach ($rows as $r) {
            $byUser[$r->user_id][$this->key($r->region, $r->realm_key, $r->name)][$r->match_id] = $r;
        }

        $wanted = [];
        $users = [];
        foreach ($byUser as $userId => $people) {
            foreach ($people as $key => $games) {
                if (count($games) >= $min) {
                    $wanted[$key] = reset($games);
                    $users[$key][] = $userId;
                }
            }
        }

        $held = BattlenetCharacter::whereNotNull('synced_at')->get(['region', 'realm_slug', 'name'])
            ->mapWithKeys(fn ($c) => [$this->key($c->region, RealmDirectory::key($c->realm_slug), $c->name) => true]);
        $todo = array_diff_key($wanted, $held->all());

        $this->info(count($wanted).' teammates with '.$min.'+ games, '.count($todo).' to look up.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $found = $missing = $failed = 0;
        $changed = [];
        foreach ($todo as $key => $p) {
            try {
                if ($sync->lookupPublic($p->region, $realms->slugOrGuess($p->realm, $p->region), $p->name)) {
                    $found++;
                    foreach ($users[$key] as $userId) {
                        $changed[$userId] = true;
                    }
                } else {
                    $missing++;
                }
            } catch (\Throwable $e) {
                $failed++;
                report($e);
            }
            usleep((int) $this->option('pause') * 1000);
        }

        foreach (array_keys($changed) as $userId) {
            BuildCoachPages::dispatch($userId);
        }

        $this->info("{$found} found, {$missing} with no public profile, {$failed} failed. Rebuilding pages for ".count($changed).' player(s).');

        return self::SUCCESS;
    }

    private function key(string $region, string $realmKey, string $name): string
    {
        return mb_strtolower($region.'|'.$realmKey.'|'.$name);
    }
}
