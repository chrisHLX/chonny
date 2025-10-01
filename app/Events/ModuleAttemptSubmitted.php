<?php
namespace App\Events;

use App\Models\Attempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleAttemptSubmitted
{
    use Dispatchable, SerializesModels;

    public $attempt;

    public function __construct(Attempt $attempt)
    {
        $this->attempt = $attempt;
    }
}