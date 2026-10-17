<?php

namespace App\Support;

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\Spell;

/**
 * What a click on an ability shows: its description and its cooldown, charges and arena duration.
 * One place for the desktop app's comp pages (CompLibraryService) and the website's spec guides
 * (2026-10-17), so both say the same thing about a spell.
 *
 * The text is the one the site's own pages show: a spec kit's, resolved against that spec's build,
 * for any copy of the spell a kit holds; else the base spell resolved with no spec. Text that would
 * still carry an unresolved formula is left out rather than shown raw (CLAUDE.md, "done").
 *
 * Kept across requests (SpellDataCache): a public page must not resolve descriptions per view.
 */
class SpellTips
{
    /** @var array<string, array{text: ?string, facts: string}|null> */
    private array $memo = [];

    /** @var array<int, array{text: ?string, cd: ?float}>|null every kit's spells, by internal spell id */
    private ?array $kitEntries = null;

    public function __construct(private ModuleSpellReferenceService $spells) {}

    /** @return array{text: ?string, facts: string}|null */
    public function for(string $name): ?array
    {
        if (! array_key_exists($name, $this->memo)) {
            $this->memo[$name] = SpellDataCache::remember('spell-tip:'.md5($name), [__FILE__], fn () => $this->compute($name));
        }

        return $this->memo[$name];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, array{text: ?string, facts: string}> the names that have a tip
     */
    public function forMany(array $names): array
    {
        $out = [];
        foreach (array_unique($names) as $name) {
            if ($tip = $this->for($name)) {
                $out[$name] = $tip;
            }
        }

        return $out;
    }

    private function compute(string $name): ?array
    {
        // The pressable copy with a description, as the site prefers (CLAUDE.md rule 3).
        $spell = Spell::query()
            ->where('patch_id', Patch::where('is_current', true)->value('id'))
            ->where('name', $name)
            ->orderByRaw('description is null')->orderBy('is_passive')->orderBy('not_in_spellbook')
            ->first();
        if (! $spell) {
            return null;
        }

        $kit = null;
        foreach (Spell::query()->where('patch_id', $spell->patch_id)->where('name', $name)->pluck('id') as $id) {
            if ($kit = $this->kitEntries()[$id] ?? null) {
                break;
            }
        }
        $text = $kit['text'] ?? null;
        if ($text === null) {
            try {
                $text = $this->spells->resolveDescription($spell, new ModuleGameBuild([]))['text'] ?? null;
            } catch (\Throwable) {
                $text = null;
            }
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        // A value the resolver could not work out reads "(varies)": the description is left out and
        // the facts stand alone (2026-10-17, for the public spec guides: Lightning Lasso, Owlkin
        // Adept, Hemotoxin and Mindgames among the PvP talents), as is any raw formula.
        if ($text === '' || preg_match('/\$[\w{]|\(varies/', $text)) {
            $text = null;
        }

        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        $cooldown = $kit['cd'] ?? $spell->cooldown_seconds;
        $facts = array_filter([
            $cooldown > 0 ? $num($cooldown).'s cooldown' : null,
            $spell->charges > 1 ? $spell->charges.' charges' : null,
            $spell->pvp_duration_seconds > 0 ? 'lasts '.$num($spell->pvp_duration_seconds).'s in arena'
                : ($spell->duration_seconds > 0 && $spell->duration_seconds < 60 ? 'lasts '.$num($spell->duration_seconds).'s' : null),
        ]);

        return ($text === null && ! $facts) ? null : ['text' => $text, 'facts' => implode(' · ', $facts)];
    }

    /**
     * Every precomputed spec kit's description and cooldown, by internal spell id (the kits carry
     * internal ids: CLAUDE.md rule 32). Read once per process, only when a tip is not cached.
     */
    private function kitEntries(): array
    {
        if ($this->kitEntries !== null) {
            return $this->kitEntries;
        }
        $this->kitEntries = [];
        foreach (glob(base_path('data/spell-kits/*/*.json')) ?: [] as $file) {
            foreach (json_decode((string) file_get_contents($file), true)['entries'] ?? [] as $e) {
                $this->kitEntries[$e['spellId']] ??= ['text' => $e['description']['text'] ?? null, 'cd' => $e['cooldown']['seconds'] ?? null];
            }
        }

        return $this->kitEntries;
    }
}
