<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use Illuminate\Support\Facades\DB;

/**
 * A spell's name in another language's combat log, to its English name (2026-10-27,
 * docs/international.md). A Chinese client logs 激流 where an English one logs Riptide, and a round
 * keeps the log's name; icons (SpellIconIndex) and the arena frames' presses (WatchPageService) look
 * spells up by the English one, so without this a Chinese game drew no icons and its frames never
 * showed a button used.
 *
 * Two sources, both by Blizzard's spell id:
 *  - learn(): every uploaded round's own lines put the id beside the name (`61295,"激流",0x8`).
 *  - fromBlizzard(): the Game Data API's localized names, for rounds stored before (the command
 *    wow:translate-spell-names). Its global API returns every locale, China's included.
 *
 * Only names with a non-ASCII character are kept: an English log's names are never touched. A German
 * or French name with no accent in it is not caught, which no player has needed yet.
 */
class SpellNameTranslations
{
    /** Search Blizzard in these locales, by the name's script. */
    private const LOCALES = [
        '/\p{Hangul}/u' => ['ko_KR'],
        '/\p{Han}/u' => ['zh_CN', 'zh_TW'],
        '/\p{Cyrillic}/u' => ['ru_RU'],
    ];

    private const LATIN_LOCALES = ['de_DE', 'fr_FR', 'es_ES', 'es_MX', 'it_IT', 'pt_BR'];

    /** @var array<string, ?string> */
    private array $cache = [];

    public static function foreign(string $name): bool
    {
        return preg_match('/[^\x00-\x7F]/', $name) === 1;
    }

    /**
     * The English name for each foreign name that has one. English names, and foreign ones not yet
     * learned, are left out: callers keep the name they had.
     *
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    public function english(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, fn ($n) => is_string($n) && self::foreign($n))));
        $missing = array_values(array_diff($names, array_keys($this->cache)));
        if ($missing !== []) {
            // The column compares without case or accents (MySQL's collation), so match exactly here.
            $rows = DB::table('spell_name_translations')->whereIn('name', $missing)->pluck('english', 'name');
            foreach ($missing as $name) {
                $this->cache[$name] = null;
            }
            foreach ($rows as $name => $english) {
                if (array_key_exists($name, $this->cache)) {
                    $this->cache[$name] = $english;
                }
            }
        }

        return array_filter(array_intersect_key($this->cache, array_flip($names)));
    }

    /**
     * Learn every foreign spell name a round's lines show beside its id, under the English name our
     * spell data holds for that id. Returns how many were new.
     */
    public function learn(string $raw): int
    {
        // `,<id>,"<name>",0x<school>`: the spell prefix of every SPELL_* and aura line. A unit's name
        // follows its GUID (Player-..., Creature-...), never a bare number, so it cannot match.
        if (! preg_match_all('/,(\d+),"([^"]*[\x80-\xFF][^"]*)",0x[0-9a-fA-F]+/', $raw, $m, PREG_SET_ORDER)) {
            return 0;
        }
        $byName = [];
        foreach ($m as [, $id, $name]) {
            $byName[$name] ??= (int) $id;
        }

        return $this->store($byName, []);
    }

    /**
     * Look foreign names up in Blizzard's localized spell names: an exact match in the locale the
     * name's script points to, preferring a spell our data holds. Returns how many were stored.
     *
     * @param  array<int, string>  $names
     */
    public function fromBlizzard(array $names, ?callable $progress = null): int
    {
        $client = app(BattlenetClient::class);
        $byName = [];
        $englishOf = [];
        foreach ($names as $name) {
            $found = [];
            foreach ($this->localesFor($name) as $locale) {
                $result = $client->gameDataAllLocales('us', '/data/wow/search/spell', ["name.{$locale}" => $name, '_pageSize' => 1000]);
                foreach ($result['results'] ?? [] as $r) {
                    if (($r['data']['name'][$locale] ?? null) === $name && isset($r['data']['id'])) {
                        $found[(int) $r['data']['id']] = $r['data']['name']['en_US'] ?? null;
                    }
                }
                if ($found !== []) {
                    break;
                }
            }
            if ($found !== []) {
                $known = $this->englishById(array_keys($found));
                $id = array_key_first(array_intersect_key($found, $known)) ?? array_key_first($found);
                $byName[$name] = $id;
                $englishOf[$id] = $known[$id] ?? $found[$id];
            }
            $progress && $progress($name, $found !== []);
        }

        return $this->store($byName, $englishOf);
    }

    /**
     * @param  array<string, int>  $byName  foreign name => Blizzard spell id
     * @param  array<int, ?string>  $englishOf  an English name to use where our data has none
     */
    private function store(array $byName, array $englishOf): int
    {
        $known = DB::table('spell_name_translations')->whereIn('name', array_keys($byName))->pluck('name')->all();
        $byName = array_diff_key($byName, array_flip($known));
        if ($byName === []) {
            return 0;
        }
        $english = $this->englishById(array_values($byName)) + array_filter($englishOf);
        $rows = [];
        foreach ($byName as $name => $id) {
            if (isset($english[$id]) && $english[$id] !== $name) {
                $rows[] = ['name' => $name, 'english' => $english[$id], 'spell_id' => $id, 'created_at' => now(), 'updated_at' => now()];
                $this->cache[$name] = $english[$id];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('spell_name_translations')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /**
     * Our spell data's English name for each Blizzard id it holds.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function englishById(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_unique($ids), 500) as $chunk) {
            $out += Spell::query()
                ->where('patch_id', Patch::where('is_current', true)->value('id'))
                ->whereIn('spell_id', $chunk)
                ->pluck('name', 'spell_id')->all();
        }

        return $out;
    }

    /** @return array<int, string> */
    private function localesFor(string $name): array
    {
        foreach (self::LOCALES as $pattern => $locales) {
            if (preg_match($pattern, $name)) {
                return $locales;
            }
        }

        return self::LATIN_LOCALES;
    }
}
