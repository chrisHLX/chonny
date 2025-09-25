<?php

namespace App\Events;

use App\Models\UserModuleHistory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Event fired when a user attempts a module (used for generating new modules to improve learning)
class ModuleAttempted
{
    use Dispatchable, SerializesModels;

    public $history;

    public function __construct(UserModuleHistory $history)
    {
        $this->history = $history;
    }
}
