<?php

namespace App\Console\Commands;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Resolves icon filenames for spells Blizzard's own public Game Data API doesn't index under
 * any spell_id at all (confirmed 2026-08-24 — see data/spelldata/icon-name-overrides.txt's own
 * header for the full "why" and how fetch-spell-icons.php applies what this command finds).
 *
 * Two things were tried and rejected before landing on the endpoint this command actually uses:
 * - Plain curl/Http against www.wowhead.com spell pages — blocked by CloudFront/Cloudflare bot
 *   protection with a flat 403, regardless of User-Agent. Not fixable with request tweaks.
 * - The `WebFetch` tool — gets PAST the block (different network path), but its HTML-to-markdown
 *   conversion strips the icon out entirely, since Wowhead renders it as a CSS background-image
 *   on an <ins> element, not a plain <img src="">. No prompt variation recovered it.
 *
 * What actually works: `https://nether.wowhead.com/tooltip/spell/{id}` — the same JSON endpoint
 * Wowhead's own embeddable tooltip widget calls client-side. It sits on a different subdomain
 * with no bot-protection observed, and returns clean `{"name": ..., "icon": ..., "tooltip": ...}`
 * JSON. Verified against a case with an already-known-correct answer before trusting it for new
 * ones: spell_id 199786 (Glacial Spike) returns `icon: "ability_mage_glacialspike"`, exactly
 * matching the icon a human confirmed by reading Wowhead's page directly.
 *
 * This is read-only discovery + verification — it never touches the database or downloads
 * anything itself. `--apply` only appends new, verified lines to
 * data/spelldata/icon-name-overrides.txt; run data/spelldata/fetch-spell-icons.php afterward
 * (the existing, tested download/DB-update mechanism) to actually apply them. Kept as two
 * separate steps deliberately — this command's job is "discover and verify," not "write to
 * spells.icon_name," matching the project's standing preview-then-apply precedent (the Blizzard
 * talent-string importer, the murlok default-build importer) rather than a single opaque command
 * that does everything at once.
 */
class ResolveWowheadIcons extends Command
{
    protected $signature = 'wow:resolve-wowhead-icons
        {spellId? : A single Blizzard spell_id to resolve. Omit to scan every spell in the current patch whose icon is still missing.}
        {--apply : Append newly-verified results to data/spelldata/icon-name-overrides.txt (skips anything already in the file)}';

    protected $description = 'Looks up icon filenames on Wowhead for spells Blizzard\'s own API does not index, and verifies each on Blizzard\'s icon CDN before trusting it.';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    private const CDN_BASE = 'https://render.worldofwarcraft.com/us/icons/56/';
    private const OVERRIDES_PATH = 'data/spelldata/icon-name-overrides.txt';

    public function handle(): int
    {
        $patchId = Patch::where('is_current', true)->value('id');
        if (!$patchId) {
            $this->error('No current patch found.');
            return self::FAILURE;
        }

        $targets = $this->resolveTargets($patchId);

        if ($targets->isEmpty()) {
            $this->info('Nothing to resolve — every spell in the current patch already has an icon.');
            return self::SUCCESS;
        }

        $this->info("Checking {$targets->count()} spell(s) against Wowhead...\n");

        $alreadyInFile = $this->existingOverrideSpellIds();
        $resolved = [];
        $newLines = [];

        foreach ($targets as $anchorSpellId => $displayName) {
            usleep(300000); // 300ms courtesy delay — this is a small, occasional, human-triggered lookup, not a bulk crawl

            $tooltip = $this->fetchTooltip($anchorSpellId);

            if ($tooltip === null || empty($tooltip['icon'])) {
                $this->line("  <fg=red>✗</> {$displayName} (spell_id {$anchorSpellId}) — no icon found on Wowhead");
                continue;
            }

            $icon = $tooltip['icon'];
            $verified = $this->verifyOnCdn($icon);

            if (!$verified) {
                $this->line("  <fg=red>✗</> {$displayName} (spell_id {$anchorSpellId}) — Wowhead says '{$icon}' but it 404s on Blizzard's CDN, skipping");
                continue;
            }

            $this->line("  <fg=green>✓</> {$displayName} (spell_id {$anchorSpellId}) → {$icon}");
            $resolved[] = ['spell_id' => $anchorSpellId, 'name' => $displayName, 'icon' => $icon];

            if (in_array($anchorSpellId, $alreadyInFile, true)) {
                continue;
            }
            $newLines[] = "{$anchorSpellId} | {$icon} | {$displayName} (resolved via wow:resolve-wowhead-icons)";
        }

        $this->newLine();
        $this->info(count($resolved) . ' of ' . $targets->count() . ' resolved and verified.');

        if (!$this->option('apply')) {
            if ($newLines !== []) {
                $this->comment('Re-run with --apply to append these to ' . self::OVERRIDES_PATH . ':');
                foreach ($newLines as $line) {
                    $this->line("  {$line}");
                }
            }
            return self::SUCCESS;
        }

        if ($newLines === []) {
            $this->info('Nothing new to append (already in the overrides file or nothing resolved).');
            return self::SUCCESS;
        }

        $path = base_path(self::OVERRIDES_PATH);
        file_put_contents($path, "\n" . implode("\n", $newLines) . "\n", FILE_APPEND);
        $this->info(count($newLines) . ' line(s) appended to ' . self::OVERRIDES_PATH . '.');
        $this->comment('Run `php data/spelldata/fetch-spell-icons.php` to actually download the files and update the database.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string> anchor spell_id => display name
     */
    private function resolveTargets(int $patchId): \Illuminate\Support\Collection
    {
        if ($single = $this->argument('spellId')) {
            $spell = Spell::where('spell_id', (int) $single)->where('patch_id', $patchId)->first();
            if (!$spell) {
                $this->error("spell_id {$single} not found in the current patch.");
                return collect();
            }
            return collect([$spell->spell_id => $spell->display_name]);
        }

        // Scoped to exactly the same target set fetch-spell-icons.php's API-driven pass already
        // covers (talent picks, PvP talents, verified-override baseline abilities, explicit-
        // cooldown baseline abilities, and every spell referenced by a promoted rotation file) —
        // NOT every row in the whole spells table. A first version of this method queried every
        // patch-wide icon-less spell unscoped and found 5,010 candidates on a real run — the
        // overwhelming majority old ranks/hidden internal effects/deprecated spells no display
        // path in this app ever surfaces. Hitting a third party's server that many times for
        // spells nothing shows would be real, unnecessary scope creep — this mirrors the same
        // narrower scope for the same reason fetch-spell-icons.php's own docblock gives.
        $dbIds = $this->displayedSpellDbIds($patchId);

        if ($dbIds->isEmpty()) {
            return collect();
        }

        return Spell::whereIn('id', $dbIds)
            ->whereNull('icon_name')
            ->get(['spell_id', 'name'])
            ->groupBy(fn (Spell $s) => $s->display_name)
            ->map(fn ($group) => $group->min('spell_id'))
            ->flip();
    }

    /**
     * @return \Illuminate\Support\Collection<int, int> spells.id (internal PK) values
     */
    private function displayedSpellDbIds(int $patchId): \Illuminate\Support\Collection
    {
        $talent = \DB::table('talent_node_entries')
            ->join('talent_nodes', 'talent_nodes.id', '=', 'talent_node_entries.talent_node_id')
            ->join('talent_trees', 'talent_trees.id', '=', 'talent_nodes.talent_tree_id')
            ->where('talent_trees.patch_id', $patchId)
            ->distinct()->pluck('talent_node_entries.spell_id');

        $pvp = \DB::table('pvp_talents')->where('patch_id', $patchId)->distinct()->pluck('spell_id');

        $verifiedOverride = \DB::table('spell_class_availability')
            ->join('spells', 'spells.id', '=', 'spell_class_availability.spell_id')
            ->where('spells.patch_id', $patchId)
            ->where('spell_class_availability.source', 'verified_override')
            ->distinct()->pluck('spell_class_availability.spell_id');

        // Mirrors TalentSelectionService::explicitBaselineCooldownAbilityIds()'s exact filter.
        $explicitBaselineCooldown = \DB::table('spell_class_availability as sca')
            ->join('spells as s', 's.id', '=', 'sca.spell_id')
            ->where('s.patch_id', $patchId)
            ->where('sca.source', 'baseline')
            ->whereNotNull('sca.spec_id')
            ->where('s.is_passive', false)
            ->where('s.not_in_spellbook', false)
            ->where('s.name', 'not like', '%(desc=%')
            ->where(fn ($q) => $q->where('s.cooldown_seconds', '>=', 10)->orWhereIn('s.mechanic', ['Sleep', 'Disorient']))
            ->distinct()->pluck('sca.spell_id');

        $rotationExternalIds = [];
        foreach (glob(base_path('data/arena-logs/rotations/*/*.json')) as $file) {
            $data = json_decode(file_get_contents($file), true);
            foreach (($data['topDpsWindowsByLength'] ?? []) as $window) {
                foreach (($window['steps'] ?? []) as $step) {
                    if (!empty($step['spellId'])) $rotationExternalIds[(int) $step['spellId']] = true;
                }
            }
            foreach (($data['topDpsWindow']['steps'] ?? []) as $step) {
                if (!empty($step['spellId'])) $rotationExternalIds[(int) $step['spellId']] = true;
            }
        }
        $rotationDbIds = $rotationExternalIds === []
            ? collect()
            : Spell::where('patch_id', $patchId)->whereIn('spell_id', array_keys($rotationExternalIds))->pluck('id');

        return $talent->merge($pvp)->merge($verifiedOverride)->merge($explicitBaselineCooldown)->merge($rotationDbIds)->unique()->values();
    }

    private function existingOverrideSpellIds(): array
    {
        $path = base_path(self::OVERRIDES_PATH);
        if (!is_file($path)) {
            return [];
        }

        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $first = trim(explode('|', $line)[0] ?? '');
            if (ctype_digit($first)) {
                $ids[] = (int) $first;
            }
        }

        return $ids;
    }

    /**
     * @return array{name: string, icon: string}|null
     */
    private function fetchTooltip(int $spellId): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(10)
                ->retry(2, 500)
                ->get("https://nether.wowhead.com/tooltip/spell/{$spellId}");
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['name']) || empty($data['icon'])) {
            return null;
        }

        return ['name' => $data['name'], 'icon' => $data['icon']];
    }

    private function verifyOnCdn(string $icon): bool
    {
        try {
            $response = Http::timeout(10)->head(self::CDN_BASE . $icon . '.jpg');
        } catch (\Throwable) {
            return false;
        }

        return $response->successful();
    }
}
