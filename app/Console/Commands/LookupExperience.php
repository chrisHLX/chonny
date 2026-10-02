<?php

namespace App\Console\Commands;

use App\Http\Services\PlayerExperienceService;
use Illuminate\Console\Command;

/**
 * Looks up players' experience and prints it as JSON, for the desktop app's live panel: as the gates
 * open it knows who is in the game and asks for anyone it has not seen before. About a second a
 * character from Blizzard; anyone already cached comes straight back.
 *
 *   php artisan wow:experience Lhactose-Area52-US Othree-Sargeras-US
 */
class LookupExperience extends Command
{
    protected $signature = 'wow:experience {names* : Players as the combat log names them, Name-Realm-Region}';

    protected $description = 'Look up players\' arena experience and print it as JSON';

    public function handle(PlayerExperienceService $experience): int
    {
        if (! config('services.battlenet.client_id')) {
            $this->error('No Blizzard credentials (BLIZZARD_CLIENT_ID) - experience cannot be looked up here.');

            return self::FAILURE;
        }

        $out = [];
        foreach (array_unique($this->argument('names')) as $name) {
            $out[$name] = $experience->lookup($name);
        }

        $this->line(json_encode($out, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
