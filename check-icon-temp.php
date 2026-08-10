<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$s = App\Models\Spell::where('name', 'Barkskin')->first();
echo "Barkskin: icon_name=" . var_export($s->icon_name, true) . "\n";

foreach (App\Models\Spell::whereNull('icon_name')->whereHas('classAvailability', fn($q) => $q->where('source', 'verified_override'))->get() as $s) {
    echo "still missing: spell_id={$s->spell_id} name={$s->name}\n";
}
