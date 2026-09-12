<?php

namespace App\Livewire\Battlenet;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\CharacterTalentResolver;
use App\Models\BattlenetCharacter;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One of your characters: exp, ratings, gear, and each spec's talent build in the real calculator.
 *
 * OWNER-ONLY, and a stranger gets a 404 rather than a 403 for the same reason a private guide does:
 * a 403 confirms the character is linked here. A character reaches anyone else only through a guide
 * its owner attributed to it, which renders the same components.
 */
class CharacterShow extends Component
{
    public BattlenetCharacter $character;

    /** Blizzard's spec id for the talent tab on screen; null means the active spec. */
    public ?int $specExternalId = null;

    public function mount(BattlenetCharacter $character): void
    {
        abort_unless($character->isOwnedBy(auth()->user()), 404);

        $this->character = $character;

        PageViewEvent::log('battlenet_character', $character->class_id, $character->spec_id);
    }

    public function selectSpec(int $specExternalId): void
    {
        if (collect($this->character->talents ?? [])->contains('spec_external_id', $specExternalId)) {
            $this->specExternalId = $specExternalId;
            unset($this->talentView);
        }
    }

    public function refresh(): void
    {
        app(BattlenetCharacterSyncService::class)->syncDetails($this->character);
        $this->character->refresh();
        unset($this->talentView);
    }

    #[Computed]
    public function talentView(): ?array
    {
        return app(CharacterTalentResolver::class)->forCharacter($this->character, $this->specExternalId);
    }

    public function render()
    {
        $this->character->loadMissing(['gameClass', 'specialization.gameClass']);

        return view('livewire.battlenet.character-show')->layout('layouts.app', [
            'title' => "{$this->character->name} | MindCollector",
            'description' => 'Your character — exp, ratings, gear and talents.',
        ]);
    }
}
