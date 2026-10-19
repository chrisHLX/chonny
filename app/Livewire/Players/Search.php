<?php

namespace App\Livewire\Players;

use App\Http\Services\RealmDirectory;
use App\Models\BattlenetCharacter;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * /wow/players: look up any character, the way Check-PvP does (2026-10-19). Typing a name and realm
 * only leads to the player's page; the lookup itself happens there, in a browser (Players\Show).
 *
 * Takes the name and realm apart, or "Name-Realm" in one box, or a Check-PvP or Armory link pasted
 * whole.
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
            'realms' => $this->realms,
            'recent' => $this->recent,
            'regions' => self::regions(),
        ])->layout('layouts.app', [
            'title' => 'WoW PvP player lookup: ratings, exp and talents | MindCollector',
            'description' => 'Look up any World of Warcraft character: current arena and Solo Shuffle ratings, highest rating ever (exp), Gladiator titles, gear and talents, from Blizzard.',
        ]);
    }
}
