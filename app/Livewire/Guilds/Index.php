<?php

namespace App\Livewire\Guilds;

use App\Models\Guild;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The guilds you belong to, and a one-field form to start another.
 *
 * A guild is the group a real arena team already is. Before this, sharing a guide with five
 * regular partners meant adding all five to every guide individually, by email — guild membership
 * replaces that with one decision made once.
 */
class Index extends Component
{
    public string $name = '';

    public ?string $error = null;

    #[Computed]
    public function guilds()
    {
        return auth()->user()->guilds()->withCount(['members', 'guides'])->get();
    }

    public function mount(): void
    {
        PageViewEvent::log('guilds_index');
    }

    /**
     * Create a guild and join it as owner, in one transaction — a guild whose creator is not a
     * member of it is a broken state that nothing else in the app expects.
     */
    public function create(): void
    {
        $name = trim($this->name);
        $this->error = null;

        if ($name === '') {
            $this->error = 'Give the guild a name.';

            return;
        }

        $guild = null;

        \DB::transaction(function () use ($name, &$guild) {
            $guild = Guild::create([
                'owner_id' => auth()->id(),
                'name' => mb_substr($name, 0, 60),
            ]);

            $guild->join(auth()->user());
        });

        $this->name = '';
        unset($this->guilds);

        $this->redirect(route('guilds.show', $guild), navigate: true);
    }

    public function render()
    {
        return view('livewire.guilds.index')->layout('layouts.app', [
            'title' => 'Your guilds | MindCollector',
            'description' => 'Groups you share arena guides with.',
        ]);
    }
}
