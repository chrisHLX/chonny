<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;

/**
 * Extracts what each real player in an already-fetched arena match actually cast
 * (SPELL_CAST_SUCCESS events, via ArenaLogService::extractCastSpellsByPlayer()) and merges
 * it into one cumulative, deduped, plain-text file per (class, spec) under
 * data/arena-logs/spell-usage/{classSlug}/{specSlug}.txt (via
 * ArenaLogService::mergeSpellUsage(), shared with wow:discover-spec-spells) — growing every
 * time a new match touching that spec gets processed, same append-and-accumulate spirit as
 * spellbook_snapshots (each new export/match only ever adds coverage, never replaces prior
 * evidence).
 *
 * File format, one spell per line, sorted by spell_id — deliberately the same
 * `spell_id | name` shape (minus the class/spec columns, since the whole file is already
 * scoped to one) as the rest of data/spelldata's hand-curated override files:
 *   1833 | Cheap Shot
 *
 * A leading `# seen in matches: {id}, {id}, ...` comment line tracks provenance (which
 * matches actually contributed) so this stays auditable, not just a black-box accumulated
 * set — matches this project's standing preference for hand-inspectable text over opaque
 * derived data.
 *
 * Usage: php artisan wow:extract-arena-spells {matchId}
 *
 * See wow:diff-arena-spells for what this feeds into, and its docblock for the important
 * caveat about how partial a single match's (or even several matches') cast list is
 * compared to a full spellbook export.
 */
class ExtractArenaSpells extends Command
{
    protected $signature = 'wow:extract-arena-spells {matchId : A match already pulled via wow:fetch-arena-log}';

    protected $description = 'Extract per-class/spec cast-spell lists from an already-fetched match and merge into data/arena-logs/spell-usage/';

    public function handle(ArenaLogService $service): int
    {
        $matchId = $this->argument('matchId');

        try {
            $players = $service->extractCastSpellsByPlayer($matchId);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($players === []) {
            $this->warn('No real players found in this match\'s metadata.');

            return self::SUCCESS;
        }

        foreach ($players as $player) {
            if ($player['specId'] === null) {
                $this->warn("Skipping {$player['name']}: spec external_id={$player['specExternalId']} not found in our specializations table (patch mismatch?).");

                continue;
            }

            $class = GameClass::find($player['classId']);
            $spec = Specialization::find($player['specId']);

            $service->mergeSpellUsage($class->slug, $spec->slug, $matchId, $player['spells']);

            $this->info("{$player['name']} ({$class->name} / {$spec->name}): ".count($player['spells'])." distinct spells cast -> data/arena-logs/spell-usage/{$class->slug}/{$spec->slug}.txt");
        }

        return self::SUCCESS;
    }
}
