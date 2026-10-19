<?php

namespace App\Livewire\Players;

use App\Http\Services\RealmDirectory;
use App\Models\ArenaRoundPlayer;
use App\Models\BattlenetCharacter;
use App\Models\PageViewEvent;
use App\Models\PvpLeaderboardEntry;
use App\Support\PlayerUrl;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * /wow/players: look up any character, the way Check-PvP does (2026-10-19). Typing a name and realm
 * only leads to the player's page; the lookup itself happens there, in a browser (Players\Show).
 *
 * Takes the name and realm apart, or "Name-Realm" in one box, or a Check-PvP or Armory link pasted
 * whole. As a name is typed, the characters it could be are suggested (suggestions()).
 */
class Search extends Component
{
    public string $region = 'us';

    public string $name = '';

    public string $realm = '';

    public function mount(): void
    {
        PageViewEvent::log('player_search');
    }

    public function updatedRegion(): void
    {
        if (! in_array($this->region, self::regions(), true)) {
            $this->region = 'us';
        }
        unset($this->realms);
    }

    public function search(RealmDirectory $directory)
    {
        $this->updatedRegion();
        [$name, $realm, $region] = self::parse(trim($this->name), trim($this->realm), $this->region);
        $this->region = $region;

        if (! preg_match('/^\p{L}{2,12}$/u', $name)) {
            $this->addError('name', 'A character name is 2 to 12 letters.');

            return null;
        }

        if ($realm === '') {
            $this->addError('realm', 'Which realm is the character on?');

            return null;
        }

        $slug = $directory->slugFor($realm, $region);
        if ($slug === null) {
            $this->addError('realm', 'There is no realm called "'.$realm.'" in '.strtoupper($region).'.');

            return null;
        }

        PageViewEvent::log('player_search', null, null, $region);

        return $this->redirectRoute('players.show', ['region' => $region, 'realm' => $slug, 'name' => mb_strtolower($name)], navigate: true);
    }

    /**
     * Name, realm and region from what was typed: "Name-Realm" in the name box, or a link from
     * Check-PvP (check-pvp.fr/us/Realm/Name) or the Armory (worldofwarcraft.com/en-us/character/us/realm/name).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function parse(string $name, string $realm, string $region): array
    {
        if (preg_match('#/(us|eu|kr|tw)/([^/?\#]+)/([^/?\#]+)/?(?:[?\#].*)?$#i', $name, $m)) {
            return [rawurldecode($m[3]), str_replace('-', ' ', rawurldecode($m[2])), strtolower($m[1])];
        }

        if ($realm === '' && str_contains($name, '-')) {
            [$name, $realm] = explode('-', $name, 2);
        }

        return [trim($name), trim($realm), $region];
    }

    /** @return list<string> */
    public static function regions(): array
    {
        return config('services.battlenet.regions', ['us', 'eu']);
    }

    #[Computed]
    public function realms(): array
    {
        return array_column(app(RealmDirectory::class)->realms($this->region), 'name');
    }

    /**
     * Characters whose name starts with what is typed, as Check-PvP suggests them: from the
     * leaderboards (highest rating first), the players in uploaded games, and those looked up here.
     * "Name-Rea" narrows to realms starting "Rea". The typed region first, then the others.
     *
     * @return list<array{name: string, realm: string, region: string, url: string, class: ?string, detail: string}>
     */
    #[Computed]
    public function suggestions(): array
    {
        [$name, $realm] = array_pad(explode('-', trim($this->name), 2), 2, '');
        $name = trim($name);
        if (mb_strlen($name) < 2 || str_contains($this->name, '/')) {
            return [];
        }

        $like = addcslashes($name, '\\%_').'%';
        $realmKey = RealmDirectory::key($realm);
        $directory = app(RealmDirectory::class);
        $found = [];
        $add = function (string $region, string $realmAny, string $name, ?string $class, string $detail, int $weight) use (&$found, $directory, $realmKey) {
            $key = $region.'|'.RealmDirectory::key($realmAny).'|'.mb_strtolower($name);
            if ($realmKey !== '' && ! str_starts_with(RealmDirectory::key($realmAny), $realmKey)) {
                return;
            }
            if (isset($found[$key])) {
                return;
            }
            $realmName = $directory->nameFor($directory->slugOrGuess($realmAny, $region), $region) ?? $realmAny;
            $found[$key] = [
                'name' => $name, 'realm' => $realmName, 'region' => $region, 'class' => $class, 'detail' => $detail,
                'url' => PlayerUrl::to($region, $realmAny, $name, false),
                'weight' => $weight + ($region === $this->region ? 100000 : 0),
            ];
        };

        PvpLeaderboardEntry::query()->where('name', 'like', $like)
            ->selectRaw('region, realm_slug, name, max(rating) as rating, max(class_slug) as class_slug')
            ->groupBy('region', 'realm_slug', 'name')->orderByDesc('rating')->limit(20)->get()
            ->each(fn ($e) => $add($e->region, $e->realm_slug, $e->name, $e->class_slug, $e->rating.' rating', 50000 + (int) $e->rating));

        ArenaRoundPlayer::query()->where('name', 'like', $like)
            ->selectRaw('region, realm, name, max(class_slug) as class_slug, count(distinct match_id) as n')
            ->groupBy('region', 'realm', 'name')->orderByDesc('n')->limit(20)->get()
            ->each(fn ($p) => $add($p->region, $p->realm, $p->name, $p->class_slug, $p->n.' '.\Illuminate\Support\Str::plural('game', $p->n).' seen', 1000 + (int) $p->n));

        BattlenetCharacter::query()->whereNotNull('looked_up_at')->where('name', 'like', $like)
            ->with('gameClass')->latest('looked_up_at')->limit(20)->get()
            ->each(fn ($c) => $add($c->region, $c->realm_slug, $c->name, $c->gameClass?->slug, 'looked up', 500));

        return collect($found)->sortByDesc('weight')->take(8)->map(fn ($f) => \Illuminate\Support\Arr::except($f, 'weight'))->values()->all();
    }

    #[Computed]
    public function recent()
    {
        return BattlenetCharacter::query()
            ->whereNotNull('looked_up_at')
            ->whereNotNull('synced_at')
            ->with(['gameClass', 'specialization'])
            ->latest('looked_up_at')
            ->limit(12)
            ->get();
    }

    public function render()
    {
        return view('livewire.players.search', [
            'suggestions' => $this->suggestions,
            'classColors' => config('wow_classes.colors', []),
            'realms' => $this->realms,
            'recent' => $this->recent,
            'regions' => self::regions(),
        ])->layout('layouts.app', [
            'title' => 'WoW PvP player lookup: ratings, exp and talents | MindCollector',
            'description' => 'Look up any World of Warcraft character: current arena and Solo Shuffle ratings, highest rating ever (exp), Gladiator titles, gear and talents, from Blizzard.',
        ]);
    }
}
