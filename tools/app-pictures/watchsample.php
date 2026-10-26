<?php

// The sample Watch page for /wow/sample (2026-10-26): one real game's timeline, on the game's own
// clock, with every player's name swapped for a label at the source. The six names are known exactly
// (the round's players), so they are replaced whole and by first name everywhere they appear: frames,
// falls, casts' targets, deaths, defensives. Then the independent check: no name from names.txt
// (allnames.php, every name in the database) may be left on the page. Writes the page only when it
// is clean.
//
//   php -d memory_limit=2G tools/app-pictures/allnames.php
//   php -d memory_limit=2G tools/app-pictures/watchsample.php "2026-10-25 19:1" [--user=2]
//   -> resources/sample/wow/watch.html
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$at = $args[0] ?? exit("Usage: watchsample.php \"YYYY-MM-DD HH:MM\" [--user=2]\n");
$user = (int) (array_reduce($args, fn ($c, $a) => str_starts_with($a, '--user=') ? substr($a, 7) : $c, '2'));

$round = App\Models\ArenaRound::where('user_id', $user)->where('played_at', 'like', $at.'%')->orderBy('played_at')->first()
    ?? exit("No round at {$at}\n");
$html = app(App\Http\Services\WatchPageService::class)->html($user, $round->lobby_id);

// Labels by side and class: "You", "Your Hunter", "Their Mage" (a second of a class gets its spec).
$players = $round->payload['analysis']['players'];
$labels = [];
$taken = [];
foreach ($players as $p) {
    $words = explode(' ', $p['spec']);
    $class = in_array(implode(' ', array_slice($words, -2)), ['Death Knight', 'Demon Hunter'], true) ? implode(' ', array_slice($words, -2)) : end($words);
    $label = ! empty($p['logger']) ? 'You' : ($p['side'] === 'us' ? 'Your ' : 'Their ').$class;
    if (isset($taken[$label])) {
        $label = ($p['side'] === 'us' ? 'Your ' : 'Their ').$p['spec'];
    }
    $taken[$label] = true;
    $labels[$p['name']] = $label;                       // Name-Realm-Region
    $labels[explode('-', $p['name'])[0]] = $label;     // Name
}
uksort($labels, fn ($a, $b) => strlen($b) <=> strlen($a));
foreach ($labels as $name => $label) {
    $html = preg_replace('/(?<![\pL\pN])'.preg_quote($name, '/').'(?![\pL\pN])/u', $label, $html);
    $html = str_replace(json_encode($name), json_encode($label), $html);
}

// The independent check, as anon.py's: every name the database holds, none left on the page.
$names = array_filter(file(storage_path('app/app-pictures/names.txt'), FILE_IGNORE_NEW_LINES), fn ($n) => strlen($n) > 2);
// Real words some player or pet is also called: anon.py's, and spell words on a Watch page (Bear Form,
// Chimaeral Sting, Aspect of the Turtle; checked on 25 Oct 19:11).
$stop = ['Chi', 'Shadow', 'Soul', 'Beast', 'Efflorescence', 'Freedom', 'Boar', 'Bear', 'Chimaeral', 'Turtle'];
// Read the page's JSON escapes too: "Nearó" is "Nearó" inside the frames data.
$plain = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $html);
$left = [];
foreach ($names as $n) {
    if (! in_array($n, $stop, true) && preg_match('/(?<![\pL\pN])'.preg_quote($n, '/').'(?![\pL\pN])/u', $plain)) {
        $left[] = $n;
    }
}
if ($left) {
    exit('Names still on the page, not written: '.implode(', ', $left)."\n");
}
file_put_contents(resource_path('sample/wow/watch.html'), $html);
echo 'Wrote resources/sample/wow/watch.html ('.round(strlen($html) / 1024)." KB), no names left. Labels: ".implode(', ', array_unique(array_values($labels)))."\n";
