<?php

// Every player name in the arena_rounds payloads (Name-Realm forms, and any 'name' next to a spec or
// guid), one per line in storage/app/app-pictures/names.txt: the independent list anon.py replaces and
// checks against. Reads the database only. Run: php -d memory_limit=3G tools/app-pictures/allnames.php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$names = [];
$walk = function ($v) use (&$walk, &$names) {
    if (is_array($v)) {
        if (isset($v['name']) && is_string($v['name']) && (isset($v['spec']) || isset($v['class']) || isset($v['guid']))) {
            $names[explode('-', $v['name'])[0]] = true;
        }
        foreach ($v as $x) {
            $walk($x);
        }
    } elseif (is_string($v) && preg_match('/^([^\s\-"]{2,12})-[A-Z][\w\']+(-(US|EU|KR|TW|CN))?$/u', $v, $m)) {
        $names[$m[1]] = true;
    }
};
App\Models\ArenaRound::query()->select(['id', 'payload'])->chunkById(50, function ($rows) use ($walk) {
    foreach ($rows as $r) {
        $p = is_array($r->payload) ? $r->payload : json_decode((string) $r->payload, true);
        $walk($p);
    }
});
@mkdir(storage_path('app/app-pictures'), 0777, true);
file_put_contents(storage_path('app/app-pictures/names.txt'), implode("\n", array_keys($names)));
echo count($names)." names\n";
