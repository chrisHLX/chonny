<?php

namespace App\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The browser upload on "Your games" (/wow/coach): pick WoWCombatLog.txt, and the browser sends
 * each arena round (the same control Game Review used, livewire/partials/game-review-upload).
 * The server measures each round and builds the player's pages in the background
 * (ArenaUploadController::assemble dispatches BuildCoachPages), the same pages the desktop app
 * gets, so nothing has to be installed to use them.
 */
class GamesUpload extends Component
{
    /** Set once an upload finishes, to say the pages are being built. Server-owned (rule 23). */
    #[Locked]
    public bool $uploaded = false;

    /** Called by the uploader when it has finished. */
    public function refreshAfterUpload(): void
    {
        $this->uploaded = true;
    }

    public function render()
    {
        return <<<'BLADE'
            <div class="space-y-3">
                @include('livewire.partials.game-review-upload')
                @if ($uploaded)
                    <div class="linear-card p-4 text-[13px] text-ink-muted">
                        Your games are in. Their pages are being built now; <a href="{{ route('coach') }}" class="text-gold hover:underline">reload this page</a> in a minute to see them.
                    </div>
                @endif
            </div>
            BLADE;
    }
}
