<?php

namespace App\Console\Commands;

use App\Http\Services\TalentSelectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Compresses the two-step "make a new icon actually show up on the site" sequence into one
 * command — added 2026-08-10 after that sequence came up as a manual two-command routine
 * (fetch-spell-icons.php, then bump the spell cache version via tinker) every time a new
 * baseline-spec-overrides.txt entry gets icon-fetched. See CLAUDE.md's icon-pipeline notes
 * for why both steps are required: the fetch script only writes spells.icon_name; the spell
 * list rendered on WowComps/Spell Explorer/module pages is cached in Redis by
 * TalentSelectionService::spellCacheVersion(), so a page already viewed before the icon
 * existed keeps serving the old (icon-less) cached result until that version bumps.
 */
class RefreshSpellIcons extends Command
{
    protected $signature = 'wow:refresh-icons';

    protected $description = 'Fetches any missing spell icons and bumps the spell cache version so they show up immediately.';

    public function handle(TalentSelectionService $talentService): int
    {
        $this->info('=== Fetching missing spell icons ===');
        $result = Process::timeout(0)
            ->run(['php', base_path('data/spelldata/fetch-spell-icons.php')], function (string $type, string $output) {
                $this->output->write($output);
            });

        if (!$result->successful()) {
            $this->error('fetch-spell-icons.php reported a failure — see output above. Cache version was NOT bumped.');

            return self::FAILURE;
        }

        $talentService->bumpSpellCacheVersion();
        $this->newLine();
        $this->info('Spell cache version bumped — new icons will show up on next page load.');

        return self::SUCCESS;
    }
}
