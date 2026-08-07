<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\GameClass;
use App\Models\Specialization;
use Livewire\Livewire;
use App\Livewire\WowComps;

$evoker = GameClass::where('name', 'Evoker')->first();
$devastation = Specialization::where('class_id', $evoker->id)->where('name', 'Devastation')->first();

$component = Livewire::test(WowComps::class)
    ->set('slots.0.classId', $evoker->id)
    ->set('slots.0.specId', $devastation->id);

$html = $component->html();
echo "Hover present: " . (str_contains($html, 'Hover') ? 'YES' : 'NO') . "\n";
echo "Quell present: " . (str_contains($html, 'Quell') ? 'YES' : 'NO') . "\n";

$comp = $component->instance()->comp;
echo "Slot 0 entries count: " . count($comp[0]['entries']) . "\n";
echo "Slot 0 mainCooldowns count: " . count($comp[0]['mainCooldowns']) . "\n";
foreach ($comp[0]['mainCooldowns'] as $mc) {
    echo "  MC: {$mc['spell']->name} cd={$mc['cooldown']['seconds']}\n";
}
