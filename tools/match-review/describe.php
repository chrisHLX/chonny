<?php

// What a spell or talent actually does, as the site resolves it: for writing the "why" in a guide
// from the game's own text rather than from memory.
//   php tools/match-review/describe.php "Gift of the San'layn" "Dance of Chi-Ji" ...
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\SpellProfileBuilder;
use App\Models\Patch;
use App\Models\Spell;

$patch = Patch::where('is_current', true)->value('id');
$builder = app(SpellProfileBuilder::class);

foreach (array_slice($argv, 1) as $name) {
    // Prefer a copy with a description; one visible ability is often several internal copies.
    $spell = Spell::where('patch_id', $patch)->where('name', $name)
        ->orderByRaw('description is null')->orderBy('is_passive')->first();
    if (! $spell) {
        echo "== {$name}: NOT IN THE SPELL DATA\n\n";

        continue;
    }
    $profile = $builder->forDetail($spell);
    $text = $profile->description['text'] ?? $profile->description['resolved'] ?? null;
    if ($text === null && is_array($profile->description)) {
        $text = json_encode($profile->description, JSON_UNESCAPED_UNICODE);
    }
    $cd = $spell->cooldown_seconds ? ' cd '.(float) $spell->cooldown_seconds.'s' : '';
    $dur = $spell->duration_seconds ? ' lasts '.(float) $spell->duration_seconds.'s' : '';
    echo "== {$name} (spell {$spell->spell_id}{$cd}{$dur})\n   ".trim(preg_replace('/\s+/', ' ', strip_tags((string) ($text ?? $spell->description))))."\n\n";
}
