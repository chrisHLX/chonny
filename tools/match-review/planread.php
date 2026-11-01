<?php

// A plan from the site held against played games: the prototype behind docs/plan-overlay.md
// (2026-11-01). Takes a user_guides row, finds the rounds its team played, and for each Sequence
// section finds the attempts, what each step's owner did, and why a step did not happen when the
// stored round can say. Reads arena_rounds (version 16+, which stores the timeline); no raw log.
//
//   php tools/match-review/planread.php 137                              every round with the comp
//   php tools/match-review/planread.php 137 "2026-10-25 19:1"            one round, step by step
//   php tools/match-review/planread.php --plan=file.json --blueprint=1   a rules file, as a blueprint:
//       the opener, the cycle, the 30s cooldowns against it, the big go, control on the kill target
//   --as=Feral    one round, that spec's steps first
//   --gap=10      seconds between a section's presses that still count as one attempt
//   --link="The go=Control the healer"   read one section's first press against another's control
//   --windows=1 --from="Feral Frenzy,Bestial Wrath"   attempts anchored on their healer being locked
//       (--join=1 --min=3 --lead=1.5)
//   --user=2
//
// A step is a question for the player. "ready" means free, alive and off cooldown: it says nothing
// about range, line of sight or the call, which the log does not hold. "no kit" means the spec's
// profile or the player's talents left it out of the stored kit, so it is not proof of "not taken".
// Cooldowns missing from a kit fall back to the base in the spell data, which ignores talents.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\UserGuideSectionKind;
use App\Models\ArenaRound;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\UserGuide;

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$plain = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));
$file = $opt('plan', null);
$guide = $file ? null : (UserGuide::with(['members.specialization', 'enemies.specialization', 'sections.blocks'])->find((int) ($plain[0] ?? 0))
    ?? exit("Usage: planread.php {guide id} [\"YYYY-MM-DD HH:MM\"] [--as=Feral] [--gap=10] [--link=\"Go=Control\"], or --plan=file.json\n"));
$at = $plain[$file ? 0 : 1] ?? null;
$gap = (float) $opt('gap', '10');
$user = (int) $opt('user', '2');
$as = $opt('as', null);
$link = array_pad(explode('=', (string) $opt('link', '')), 2, '');

const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

$specs = Specialization::get(['id', 'name', 'external_spec_id'])->keyBy('id');
$specNames = $specs->mapWithKeys(fn ($s) => [(int) $s->external_spec_id => $s->name]);
$planFile = $file ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
$team = $guide
    ? $guide->members->map(fn ($m) => (int) $specs[$m->spec_id]->external_spec_id)->sort()->values()->all()
    : collect($planFile['team'])->sort()->values()->all();
$spellName = fn (int $ext) => preg_replace('/\s*\(desc=.*$/', '', Spell::where('spell_id', $ext)->orderByDesc('id')->value('name') ?? "#{$ext}");

$sections = [];
foreach ($planFile['sections'] ?? [] as $s) {
    $s['steps'] = array_map(fn ($step) => ['spell' => $step[0], 'spec' => $step[1], 'specName' => $specNames[$step[1]]], $s['steps']);
    $sections[] = $s;
}
foreach ($guide?->sections ?? [] as $s) {
    if ($s->kind !== UserGuideSectionKind::Sequence) {
        continue;
    }
    $steps = [];
    foreach ($s->blocks as $b) {
        if ($b->externalSpellId()) {
            $steps[] = ['spell' => $spellName($b->externalSpellId()), 'spec' => (int) $specs[$b->payload['source_spec_id']]->external_spec_id, 'specName' => $specs[$b->payload['source_spec_id']]->name];
        }
    }
    $sections[] = ['title' => $s->title, 'steps' => $steps];
}

/** Whether this guid is held in lockout control at $t, and by what. */
$lockedAt = function (array $a, string $guid, float $t): ?array {
    foreach ($a['timeline']['control'] ?? [] as $c) {
        if ($c[0] === $guid && in_array($c[3], LOCKOUT, true) && $t >= $c[4] && $t < $c[5]) {
            return $c;
        }
    }

    return null;
};

/** The attempts at one section in one round, each step's presses and each missing step's state. */
$baseCooldowns = [];
$baseCooldown = function (string $name) use (&$baseCooldowns): float {
    return $baseCooldowns[$name] ??= (float) Spell::where(fn ($q) => $q->where('name', $name)->orWhere('name', 'like', $name.' (desc=%'))
        ->where('cooldown_seconds', '>', 0)->orderBy('is_passive')->orderBy('not_in_spellbook')->orderByDesc('cooldown_seconds')->value('cooldown_seconds');
};

$read = function (array $a, array $section) use ($gap, $lockedAt, $baseCooldown): array {
    $bySpec = [];
    foreach ($a['players'] as $p) {
        if ($p['side'] === 'us') {
            $bySpec[(int) $p['specExternalId']] = $p;
        }
    }
    $died = [];
    foreach ($a['deaths'] ?? [] as $d) {
        $died[$d['who']] ??= (float) $d['t'];
    }

    $presses = [];
    foreach ($section['steps'] as $i => $step) {
        $p = $bySpec[$step['spec']] ?? null;
        foreach ($p ? ($a['timeline']['casts'][$p['guid']] ?? []) : [] as $c) {
            if ($c[1] === $step['spell']) {
                $presses[] = ['t' => (float) $c[0], 'step' => $i, 'on' => $c[2]];
            }
        }
    }
    usort($presses, fn ($x, $y) => $x['t'] <=> $y['t']);

    $attempts = [];
    foreach ($presses as $press) {
        $last = array_key_last($attempts);
        if ($last !== null && $press['t'] - end($attempts[$last]['presses'])['t'] <= $gap) {
            $attempts[$last]['presses'][] = $press;
        } else {
            $attempts[] = ['presses' => [$press]];
        }
    }

    foreach ($attempts as &$attempt) {
        $from = $attempt['presses'][0]['t'];
        $to = end($attempt['presses'])['t'];
        $attempt['from'] = $from;
        $attempt['to'] = $to;
        $hit = array_unique(array_column($attempt['presses'], 'step'));
        $attempt['missing'] = [];
        foreach ($section['steps'] as $i => $step) {
            if (in_array($i, $hit, true)) {
                continue;
            }
            $p = $bySpec[$step['spec']] ?? null;
            if (! $p) {
                $attempt['missing'][$i] = ['state' => 'no player'];

                continue;
            }
            $kit = collect($p['kit'] ?? [])->firstWhere('name', $step['spell']);
            $casts = collect($a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => $c[1] === $step['spell']);
            if (! $kit && $casts->isEmpty()) {
                $attempt['missing'][$i] = ['state' => 'not in their kit'];

                continue;
            }
            if (isset($died[$p['guid']]) && $died[$p['guid']] <= $to) {
                $attempt['missing'][$i] = ['state' => 'dead'];

                continue;
            }
            // A kit holds what the spec's profile lists. Anything else falls back to the base
            // cooldown in the spell data, which does not know this player's talents.
            $cd = (float) ($kit['cd'] ?? $baseCooldown($step['spell']));
            $charges = (int) ($kit['charges'] ?? 1);
            $recent = $casts->filter(fn ($c) => $c[0] < $to && $c[0] > $to - $cd)->count();
            if ($cd > 0 && $recent >= $charges) {
                $back = $casts->filter(fn ($c) => $c[0] < $to)->max(fn ($c) => $c[0]) + $cd;
                $attempt['missing'][$i] = ['state' => 'on cooldown', 'left' => round($back - $to, 1)];

                continue;
            }
            $held = $lockedAt($a, $p['guid'], $from) ?? $lockedAt($a, $p['guid'], $to);
            $attempt['missing'][$i] = $held
                ? ['state' => 'locked out', 'by' => $held[2]]
                : ['state' => $cd > 0 ? 'ready' : 'ready, no cooldown known'];
        }
    }

    return $attempts;
};

/** Our lockout on their healer around a moment: held at $t, or the next one to land. */
$healerAt = function (array $a, float $t) use ($lockedAt): array {
    $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']));
    if (! $healer) {
        return ['state' => 'no healer'];
    }
    if ($held = $lockedAt($a, $healer['guid'], $t)) {
        return ['state' => 'held', 'by' => $held[2], 'left' => round($held[5] - $t, 1)];
    }
    $next = collect($a['timeline']['control'] ?? [])
        ->filter(fn ($c) => $c[0] === $healer['guid'] && in_array($c[3], LOCKOUT, true) && $c[4] > $t)
        ->sortBy(fn ($c) => $c[4])->first();

    return $next ? ['state' => 'free', 'next' => round($next[4] - $t, 1), 'by' => $next[2]] : ['state' => 'free', 'next' => null];
};

// chunkById orders by id itself: an orderBy here makes it skip rows.
$query = ArenaRound::where('user_id', $user);
if ($at) {
    $query->where('played_at', 'like', $at.'%');
}
$matched = [];
$query->chunkById(50, function ($rounds) use (&$matched, $team) {
    foreach ($rounds as $r) {
        $a = $r->payload['analysis'] ?? [];
        if (($a['version'] ?? 0) < 16) {
            continue;
        }
        $ours = collect($a['players'] ?? [])->where('side', 'us')->pluck('specExternalId')->map(fn ($s) => (int) $s)->sort()->values()->all();
        if ($ours === $team) {
            $matched[] = ['id' => $r->id, 'at' => (string) $r->played_at, 'bracket' => $r->bracket, 'a' => array_intersect_key($a, array_flip(['won', 'players', 'deaths', 'timeline']))];
        }
    }
});
usort($matched, fn ($x, $y) => $x['at'] <=> $y['at']);
echo 'Plan '.($guide ? "#{$guide->id} {$guide->title}" : $planFile['title'])."\n";
if ($guide) {
    echo 'Team: '.$guide->members->map(fn ($m) => $m->specialization?->name)->implode(' / ').'   Enemy: '.($guide->enemies->map(fn ($m) => $m->specialization?->name)->implode(' / ') ?: 'none named')."\n";
}
echo count($matched)." round(s) with this team\n";
$enemy = $guide ? $guide->enemies->map(fn ($m) => (int) $specs[$m->spec_id]->external_spec_id)->sort()->values()->all() : [];
if ($enemy) {
    $exact = collect($matched)->filter(fn ($m) => collect($m['a']['players'])->where('side', 'them')->pluck('specExternalId')->map(fn ($s) => (int) $s)->sort()->values()->all() === $enemy)->count();
    echo "{$exact} of them against the plan's named enemy\n";
}
$enemyComps = collect($matched)->map(fn ($m) => collect($m['a']['players'])->where('side', 'them')->pluck('spec')->sort()->implode(' / '))->countBy()->sortDesc();
echo $enemyComps->count().' different enemy teams; most often met '.($enemyComps->first() ?? 0)." times\n";

// One round, step by step.
if ($at) {
    foreach ($matched as $m) {
        $a = $m['a'];
        $n = collect($a['players'])->mapWithKeys(fn ($p) => [$p['guid'] => explode('-', $p['name'])[0]]);
        echo "\n{$m['at']}  {$m['bracket']}  ".($a['won'] ? 'WON' : 'LOST')."\n";
        foreach ($sections as $section) {
            $steps = $section['steps'];
            if ($as) {
                $steps = collect($steps)->sortBy(fn ($s) => $s['specName'] === $as ? 0 : 1)->values()->all();
            }
            echo "\n  {$section['title']}\n    plan: ".collect($steps)->map(fn ($s) => "{$s['spell']} ({$s['specName']})")->implode(', ')."\n";
            foreach ($read($a, $section) as $i => $attempt) {
                printf("    attempt %d  %.1f to %.1f\n", $i + 1, $attempt['from'], $attempt['to']);
                foreach ($attempt['presses'] as $press) {
                    $step = $section['steps'][$press['step']];
                    printf("      %6.1f  %-30s %-12s %s\n", $press['t'], $step['spell'], $step['specName'], $press['on'] ? '> '.($n[$press['on']] ?? '?') : '');
                }
                foreach ($attempt['missing'] as $idx => $miss) {
                    $step = $section['steps'][$idx];
                    if ($as && $step['specName'] !== $as) {
                        continue;
                    }
                    printf("        ..  %-30s %-12s %s\n", $step['spell'], $step['specName'], $miss['state'].(isset($miss['left']) ? " ({$miss['left']}s left)" : '').(isset($miss['by']) ? " ({$miss['by']})" : ''));
                }
                $healer = $healerAt($a, $attempt['from']);
                echo '      their healer at the first press: '.$healer['state'].(isset($healer['by']) ? ' ('.$healer['by'].(isset($healer['left']) ? ", {$healer['left']}s left" : ", landed {$healer['next']}s later").')' : '')."\n";
            }
        }
        $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']));
        echo "\n  Lockout on their healer, by us\n";
        $spans = collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[0] === ($healer['guid'] ?? '') && in_array($c[3], LOCKOUT, true))->sortBy(fn ($c) => $c[4])->values();
        $held = 0.0;
        $end = -1.0;
        foreach ($spans as $c) {
            $overlap = max(0, min($end, $c[5]) - $c[4]);
            printf("    %6.1f to %6.1f  %-18s %-13s %-12s %s\n", $c[4], $c[5], $c[2], $c[3], $n[$c[1]] ?? '?', $overlap > 0 ? sprintf('overlaps the last by %.1fs', $overlap) : '');
            $held += max(0, $c[5] - max($c[4], $end));
            $end = max($end, $c[5]);
        }
    }
    exit;
}

// A plan read as a blueprint, from its rules (--plan=file.json): the opener, the cycle, the 30s
// cooldowns against the cycle, the big go from its anchor, and control on the kill target.
if ($planFile && $opt('blueprint', null) !== null) {
    $sectionFor = fn (string $key, string $value) => collect($sections)->first(fn ($s) => ($s[$key] ?? null) === $value);
    $median = function (array $v) {
        sort($v);

        return $v ? $v[(int) (count($v) / 2)] : null;
    };
    $windowsOf = function (array $a) {
        $ours = collect($a['players'])->where('side', 'us')->pluck('guid')->all();
        $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'] ?? null;
        $windows = [];
        foreach (collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[0] === $healer && in_array($c[1], $ours, true) && in_array($c[3], LOCKOUT, true))->sortBy(fn ($c) => $c[4]) as $c) {
            $l = array_key_last($windows);
            if ($l !== null && $c[4] - $windows[$l]['to'] <= 1.0) {
                $windows[$l]['to'] = max($windows[$l]['to'], $c[5]);
                $windows[$l]['spells'][] = $c[2];
            } else {
                $windows[] = ['from' => (float) $c[4], 'to' => (float) $c[5], 'spells' => [$c[2]]];
            }
        }

        return array_values(array_filter($windows, fn ($w) => $w['to'] - $w['from'] >= 3));
    };
    $pressesOf = function (array $a, int $spec, string $spell) {
        $p = collect($a['players'])->first(fn ($x) => $x['side'] === 'us' && (int) $x['specExternalId'] === $spec);

        return $p ? collect($a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => $c[1] === $spell)->values()->all() : [];
    };
    $withHealer = collect($matched)->filter(fn ($m) => collect($m['a']['players'])->contains(fn ($p) => $p['side'] === 'them' && ! empty($p['healer'])))->values();
    printf("\n%d of %d rounds met a healer\n", $withHealer->count(), count($matched));

    // The opener: the first window, and which of the plan's steps were in it.
    $opener = $sectionFor('trigger', 'opener');
    $starts = [];
    $stepCount = [];
    $sizes = [];
    $firstSpell = [];
    foreach ($withHealer as $m) {
        $w = $windowsOf($m['a'])[0] ?? null;
        if (! $w) {
            continue;
        }
        $starts[] = $w['from'];
        $firstSpell[$w['spells'][0]] = ($firstSpell[$w['spells'][0]] ?? 0) + 1;
        $hit = array_intersect(array_column($opener['steps'], 'spell'), $w['spells']);
        $sizes[] = count($hit);
        foreach ($hit as $s) {
            $stepCount[$s] = ($stepCount[$s] ?? 0) + 1;
        }
    }
    arsort($firstSpell);
    printf("\n%s: first stretch of 3s+ on their healer in %d rounds, median start %.1fs\n", $opener['title'], count($starts), $median($starts));
    echo '  it began with: '.collect($firstSpell)->map(fn ($n, $s) => "{$s} {$n}")->implode(', ')."\n";
    foreach ($opener['steps'] as $s) {
        printf("  %-20s %-14s in the first stretch in %3d rounds\n", $s['spell'], $s['specName'], $stepCount[$s['spell']] ?? 0);
    }
    printf("  plan steps in that stretch, median: %d of %d\n", $median($sizes), count($opener['steps']));

    // The cycle.
    $gaps = [];
    $count = 0;
    $seconds = 0.0;
    foreach ($withHealer as $m) {
        $w = $windowsOf($m['a']);
        $count += count($w);
        $seconds += (float) collect($m['a']['timeline']['hp'] ?? [])->map(fn ($h) => count($h))->max();
        for ($i = 1; $i < count($w); $i++) {
            $gaps[] = round($w[$i]['from'] - $w[$i - 1]['from'], 1);
        }
    }
    sort($gaps);
    $near = count(array_filter($gaps, fn ($g) => $g >= 22 && $g <= 40));
    printf("\nEvery 30s: %d stretches, one every %.0fs of play. Start to start: quarter %.0fs, median %.0fs, three quarters %.0fs; %d of %d (%d%%) were 22s to 40s apart\n", $count, $count ? $seconds / $count : 0, $gaps[(int) (count($gaps) / 4)] ?? 0, $median($gaps), $gaps[(int) (count($gaps) * 3 / 4)] ?? 0, $near, count($gaps), count($gaps) ? 100 * $near / count($gaps) : 0);

    // The 30s cooldowns against the cycle.
    $cds = $sectionFor('trigger', 'healer_locked');
    printf("\n%s against those stretches (1.5s lead)\n", $cds['title']);
    printf("  %-16s %-14s %7s %8s %11s %11s %7s\n", 'button', 'whose', 'presses', 'inside', 'up to 10s', 'up to 10s', 'apart');
    printf("  %-16s %-14s %7s %8s %11s %11s %7s\n", '', '', '', '', 'before', 'after', '');
    $leads = [];
    foreach ($cds['steps'] as $s) {
        $t = ['n' => 0, 'in' => 0, 'early' => 0, 'after' => 0, 'apart' => 0];
        foreach ($withHealer as $m) {
            $w = $windowsOf($m['a']);
            foreach ($pressesOf($m['a'], $s['spec'], $s['spell']) as $c) {
                $at0 = (float) $c[0];
                $t['n']++;
                $inside = collect($w)->first(fn ($x) => $at0 >= $x['from'] - 1.5 && $at0 <= $x['to']);
                $next = collect($w)->first(fn ($x) => $x['from'] - 1.5 > $at0);
                $prev = collect($w)->filter(fn ($x) => $x['to'] < $at0)->last();
                if ($inside) {
                    $t['in']++;
                } elseif ($next && $next['from'] - $at0 <= 10) {
                    $t['early']++;
                    if ($s['spell'] !== 'Mind Blast') {
                        $leads[] = round($next['from'] - $at0, 1);
                    }
                } elseif ($prev && $at0 - $prev['to'] <= 10) {
                    $t['after']++;
                } else {
                    $t['apart']++;
                }
            }
        }
        $p = fn ($k) => $t['n'] ? sprintf('%d (%d%%)', $t[$k], round(100 * $t[$k] / $t['n'])) : '0';
        printf("  %-16s %-14s %7d %8s %11s %11s %7s\n", $s['spell'], $s['specName'], $t['n'], $p('in'), $p('early'), $p('after'), $p('apart'));
    }
    printf("  a press ahead of the control led it by a median %.1fs\n", $median($leads));

    // The big go, from its anchor.
    $big = $sectionFor('trigger', 'our_press');
    $anchorStep = collect($big['steps'])->firstWhere('spell', $big['anchor']);
    $with = [];
    $n = 0;
    $held = 0;
    $soon = 0;
    foreach ($withHealer as $m) {
        foreach ($pressesOf($m['a'], $anchorStep['spec'], $big['anchor']) as $c) {
            $n++;
            $state = $healerAt($m['a'], (float) $c[0]);
            $held += $state['state'] === 'held' ? 1 : 0;
            $soon += $state['state'] === 'free' && ($state['next'] ?? 99) <= 3 ? 1 : 0;
            foreach ($big['steps'] as $s) {
                if ($s['spell'] === $big['anchor']) {
                    continue;
                }
                $near = collect($pressesOf($m['a'], $s['spec'], $s['spell']))->contains(fn ($x) => $x[0] >= $c[0] - 6 && $x[0] <= $c[0] + 10);
                $with[$s['spell']] = ($with[$s['spell']] ?? 0) + ($near ? 1 : 0);
            }
        }
    }
    printf("\n%s: %d presses of %s, their healer held at %d, control within 3s at %d\n", $big['title'], $n, $big['anchor'], $held, $soon);
    foreach ($with as $s => $c) {
        printf("  %-22s within 6s before or 10s after: %3d (%d%%)\n", $s, $c, $n ? 100 * $c / $n : 0);
    }

    // The long buttons: whether each was in the kit, and whether it was back at the anchor.
    foreach (['Power Infusion' => 256, 'Ultimate Penitence' => 256] as $spell => $spec) {
        $t = ['rounds' => 0, 'kit' => 0, 'pressedRounds' => 0, 'presses' => 0, 'back' => 0, 'notBack' => 0, 'together' => 0];
        foreach ($withHealer as $m) {
            $a = $m['a'];
            $p = collect($a['players'])->first(fn ($x) => $x['side'] === 'us' && (int) $x['specExternalId'] === $spec);
            $kit = collect($p['kit'] ?? [])->firstWhere('name', $spell);
            $own = $pressesOf($a, $spec, $spell);
            $t['rounds']++;
            $t['kit'] += $kit ? 1 : 0;
            $t['pressedRounds'] += $own ? 1 : 0;
            $t['presses'] += count($own);
            $cd = (float) ($kit['cd'] ?? 0);
            foreach ($pressesOf($a, 103, $big['anchor']) as $c) {
                if (collect($own)->contains(fn ($x) => $x[0] >= $c[0] - 6 && $x[0] <= $c[0] + 10)) {
                    $t['together']++;

                    continue;
                }
                $last = collect($own)->filter(fn ($x) => $x[0] < $c[0])->map(fn ($x) => $x[0])->max();
                ($cd > 0 && ($last === null || $last + $cd <= $c[0])) ? $t['back']++ : $t['notBack']++;
            }
        }
        printf("  %s: in the Disc's kit in %d of %d rounds, pressed in %d rounds (%d presses). At the Incarnations: with it %d, back and not with it %d, not back or unknown %d\n", $spell, $t['kit'], $t['rounds'], $t['pressedRounds'], $t['presses'], $t['together'], $t['back'], $t['notBack']);
    }

    // A button can be pressed and still never appear as control: read the casts against the spans.
    foreach (['Chimaeral Sting' => 253] as $spell => $spec) {
        $presses = 0;
        $onHealer = 0;
        $rounds = 0;
        $spans = 0;
        $after = [];
        foreach ($withHealer as $m) {
            $a = $m['a'];
            $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'];
            $own = $pressesOf($a, $spec, $spell);
            $rounds += $own ? 1 : 0;
            $presses += count($own);
            $onHealer += collect($own)->where(2, $healer)->count();
            $spans += collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[2] === $spell)->count();
            foreach ($own as $c) {
                foreach ($a['timeline']['control'] ?? [] as $x) {
                    if ($x[0] === $c[2] && $x[4] >= $c[0] && $x[4] <= $c[0] + 2) {
                        $after[$x[2]] = ($after[$x[2]] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($after);
        printf("\n%s: %d presses in %d rounds, %d on their healer, %d control spans under that name. Control on the same target within 2s: %s\n", $spell, $presses, $rounds, $onHealer, $spans, collect($after)->map(fn ($n, $s) => "{$s} {$n}")->take(5)->implode(', '));
    }

    // Control on the kill target: Maim on whoever the last Feral Frenzy went on.
    $maim = 0;
    $same = 0;
    $onHealer = 0;
    foreach ($withHealer as $m) {
        $healer = collect($m['a']['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'];
        $frenzy = $pressesOf($m['a'], 103, 'Feral Frenzy');
        foreach ($pressesOf($m['a'], 103, 'Maim') as $c) {
            $maim++;
            $last = collect($frenzy)->filter(fn ($f) => abs($f[0] - $c[0]) <= 15)->sortBy(fn ($f) => abs($f[0] - $c[0]))->first();
            $same += $last && $last[2] === $c[2] ? 1 : 0;
            $onHealer += $c[2] === $healer ? 1 : 0;
        }
    }
    printf("\nCC on kill target: %d Maims, %d on the nearest Feral Frenzy's target, %d on their healer\n", $maim, $same, $onHealer);

    // Who the team went after: the log holds no call, so read it from where each DPS put their casts.
    $agree = 0;
    $dpsRounds = 0;
    $shares = [];
    $killedOnFocus = 0;
    $kills = 0;
    $changed = 0;
    foreach ($withHealer as $m) {
        $a = $m['a'];
        $enemies = collect($a['players'])->where('side', 'them')->pluck('guid')->all();
        $focus = [];
        $firstHalf = null;
        foreach (collect($a['players'])->where('side', 'us')->reject(fn ($p) => ! empty($p['healer'])) as $p) {
            $on = collect($a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => in_array($c[2] ?? null, $enemies, true));
            if ($on->count() < 10) {
                continue;
            }
            $counts = $on->countBy(fn ($c) => $c[2])->sortDesc();
            $focus[] = $counts->keys()->first();
            $shares[] = $counts->first() / $on->count();
        }
        if (count($focus) !== 2) {
            continue;
        }
        $dpsRounds++;
        $agree += $focus[0] === $focus[1] ? 1 : 0;
        // The target in the first third against the rest, across both DPS.
        $end = (float) collect($a['timeline']['hp'] ?? [])->map(fn ($h) => count($h))->max();
        $all = collect($a['players'])->where('side', 'us')->reject(fn ($p) => ! empty($p['healer']))
            ->flatMap(fn ($p) => $a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => in_array($c[2] ?? null, $enemies, true));
        $early = $all->filter(fn ($c) => $c[0] < $end / 3)->countBy(fn ($c) => $c[2])->sortDesc()->keys()->first();
        $late = $all->filter(fn ($c) => $c[0] >= $end / 3)->countBy(fn ($c) => $c[2])->sortDesc()->keys()->first();
        $changed += $early !== $late ? 1 : 0;
        $dead = collect($a['deaths'] ?? [])->first(fn ($d) => in_array($d['who'], $enemies, true));
        if ($dead) {
            $kills++;
            $killedOnFocus += in_array($dead['who'], $focus, true) ? 1 : 0;
        }
    }
    sort($shares);
    printf("\nWho the team went after, from casts: %d rounds. Both DPS mostly on one player in %d (%d%%). A DPS's casts on their own main target: median %d%%. Main target changed after the first third in %d (%d%%). Their first death was a DPS's main target in %d of %d\n", $dpsRounds, $agree, $dpsRounds ? 100 * $agree / $dpsRounds : 0, 100 * $median($shares), $changed, $dpsRounds ? 100 * $changed / $dpsRounds : 0, $killedOnFocus, $kills);

    // Damage against their healer's state: does it stick when the healer is held?
    $buckets = ['held' => [], 'within 5s' => [], 'free' => []];
    foreach ($withHealer as $m) {
        $a = $m['a'];
        $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'];
        foreach ($pressesOf($a, 103, 'Feral Frenzy') as $c) {
            $hp = $a['timeline']['hp'][$c[2] ?? ''] ?? null;
            $s = (int) $c[0];
            if (! $hp || $c[2] === $healer || ! isset($hp[$s + 14])) {
                continue;
            }
            $state = $healerAt($a, (float) $c[0]);
            $key = $state['state'] === 'held' ? 'held' : (($state['next'] ?? 99) <= 5 ? 'within 5s' : 'free');
            $start = $hp[$s];
            $low = min(array_slice($hp, $s, 9));
            $buckets[$key][] = ['drop' => $start - $low, 'kept' => $start - $hp[$s + 14]];
        }
    }
    echo "\nFeral Frenzy on someone other than their healer: their health, by their healer at the press\n";
    foreach ($buckets as $key => $rows) {
        $drops = array_column($rows, 'drop');
        $kept = array_column($rows, 'kept');
        printf("  %-10s %3d presses  lowest within 8s: median -%d points  still down at 14s: median -%d points  fell 30+ in %d%%\n", $key, count($rows), $median($drops), $median($kept), count($rows) ? 100 * count(array_filter($drops, fn ($d) => $d >= 30)) / count($rows) : 0);
    }

    // Every second of health on their DPS, while their healer was held by us or free.
    $by = ['held' => ['seconds' => 0, 'lost' => 0, 'gained' => 0], 'free' => ['seconds' => 0, 'lost' => 0, 'gained' => 0]];
    foreach ($withHealer as $m) {
        $a = $m['a'];
        $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'];
        $ours = collect($a['players'])->where('side', 'us')->pluck('guid')->all();
        $spans = collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[0] === $healer && in_array($c[1], $ours, true) && in_array($c[3], LOCKOUT, true))->values()->all();
        foreach (collect($a['players'])->where('side', 'them')->reject(fn ($p) => $p['guid'] === $healer) as $p) {
            $hp = $a['timeline']['hp'][$p['guid']] ?? [];
            for ($s = 0; $s < count($hp) - 1; $s++) {
                if ($hp[$s] === 0) {
                    break;
                }
                $key = collect($spans)->contains(fn ($c) => $s >= $c[4] && $s < $c[5]) ? 'held' : 'free';
                $change = $hp[$s + 1] - $hp[$s];
                $by[$key]['seconds']++;
                $by[$key][$change < 0 ? 'lost' : 'gained'] += abs($change);
            }
        }
    }
    echo "\nTheir DPS's health, every second of these rounds\n";
    foreach ($by as $key => $t) {
        printf("  healer %-5s %6d seconds  lost %.2f a second  gained back %.2f a second  gained per point lost %.2f\n", $key, $t['seconds'], $t['lost'] / max(1, $t['seconds']), $t['gained'] / max(1, $t['seconds']), $t['gained'] / max(1, $t['lost']));
    }

    // Ultimate Penitence, by what the moment looked like: the team in danger, or their target low.
    $uses = ['saving' => ['n' => 0, 'won' => 0], 'on the attack' => ['n' => 0, 'won' => 0], 'neither' => ['n' => 0, 'won' => 0]];
    $unused = ['n' => 0, 'won' => 0];
    foreach ($withHealer as $m) {
        $a = $m['a'];
        $own = $pressesOf($a, 256, 'Ultimate Penitence');
        if (! $own) {
            $unused['n']++;
            $unused['won'] += $a['won'] ? 1 : 0;
        }
        $us = collect($a['players'])->where('side', 'us')->pluck('guid')->all();
        $them = collect($a['players'])->where('side', 'them')->pluck('guid')->all();
        $at = function (array $guids, int $s) use ($a) {
            return collect($guids)->map(fn ($g) => $a['timeline']['hp'][$g][$s] ?? 100)->min();
        };
        foreach ($own as $c) {
            $s = (int) $c[0];
            $ours = min($at($us, $s), $at($us, max(0, $s - 1)));
            $key = $ours <= 40 ? 'saving' : ($at($them, $s) <= 60 ? 'on the attack' : 'neither');
            $uses[$key]['n']++;
            $uses[$key]['won'] += $a['won'] ? 1 : 0;
        }
    }
    echo "\nUltimate Penitence, by the moment it was pressed (our lowest at 40% or under: saving; their lowest at 60% or under: on the attack)\n";
    foreach ($uses as $key => $t) {
        printf("  %-14s %3d presses, round won %d\n", $key, $t['n'], $t['won']);
    }
    printf("  never pressed  %3d rounds, won %d\n", $unused['n'], $unused['won']);
    exit;
}

// Every round: how each section went, and why a step did not happen.
foreach ($sections as $section) {
    $rows = array_fill(0, count($section['steps']), ['pressed' => 0, 'cooldown' => 0, 'locked' => 0, 'dead' => 0, 'ready' => 0, 'kit' => 0, 'other' => 0]);
    $attempts = 0;
    $whole = 0;
    $lengths = [];
    foreach ($matched as $m) {
        foreach ($read($m['a'], $section) as $attempt) {
            $attempts++;
            $whole += $attempt['missing'] === [] ? 1 : 0;
            $lengths[] = $attempt['to'] - $attempt['from'];
            foreach (array_unique(array_column($attempt['presses'], 'step')) as $i) {
                $rows[$i]['pressed']++;
            }
            foreach ($attempt['missing'] as $i => $miss) {
                $key = match (true) {
                    $miss['state'] === 'on cooldown' => 'cooldown',
                    $miss['state'] === 'locked out' => 'locked',
                    $miss['state'] === 'dead' => 'dead',
                    str_starts_with($miss['state'], 'ready') => 'ready',
                    $miss['state'] === 'not in their kit' => 'kit',
                    default => 'other',
                };
                $rows[$i][$key]++;
            }
        }
    }
    sort($lengths);
    $median = $lengths ? $lengths[(int) (count($lengths) / 2)] : 0;
    printf("\n%s: %d attempts in %d rounds, %d with every step, median length %.1fs, longest %.1fs\n", $section['title'], $attempts, count($matched), $whole, $median, $lengths ? end($lengths) : 0);
    printf("  %-30s %-14s %8s %9s %7s %6s %6s %8s\n", 'step', 'whose', 'pressed', 'cooldown', 'locked', 'dead', 'ready', 'no kit');
    foreach ($section['steps'] as $i => $step) {
        $r = $rows[$i];
        printf("  %-30s %-14s %8d %9d %7d %6d %6d %8d\n", $step['spell'], $step['specName'], $r['pressed'], $r['cooldown'], $r['locked'], $r['dead'], $r['ready'], $r['kit']);
    }
}

// Where the team's control landed, and how much of what reached their healer overlapped.
$landed = [];
$sum = 0.0;
$union = 0.0;
$onHealer = [];
foreach ($matched as $m) {
    $a = $m['a'];
    $ours = collect($a['players'])->where('side', 'us')->pluck('guid')->all();
    $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'] ?? null;
    $end = -1.0;
    foreach (collect($a['timeline']['control'] ?? [])->sortBy(fn ($c) => $c[4]) as $c) {
        if (! in_array($c[1], $ours, true) || ! in_array($c[3], LOCKOUT, true)) {
            continue;
        }
        $landed[$c[2]][$c[0] === $healer ? 'healer' : 'other'] = ($landed[$c[2]][$c[0] === $healer ? 'healer' : 'other'] ?? 0) + 1;
        if ($c[0] === $healer) {
            $onHealer[$c[2]] = ($onHealer[$c[2]] ?? 0) + ($c[5] - $c[4]);
            $sum += $c[5] - $c[4];
            $union += max(0, $c[5] - max($c[4], $end));
            $end = max($end, $c[5]);
        }
    }
}
$planned = collect($sections)->flatMap(fn ($s) => array_column($s['steps'], 'spell'))->unique()->all();
uasort($landed, fn ($x, $y) => array_sum($y) <=> array_sum($x));
echo "\nOur lockout landed, by spell (in the plan? on their healer / on anyone else)\n";
foreach (array_slice($landed, 0, 14, true) as $spell => $t) {
    printf("  %-24s %-8s healer %3d  other %3d\n", $spell, in_array($spell, $planned, true) ? 'plan' : 'off plan', $t['healer'] ?? 0, $t['other'] ?? 0);
}
printf("Lockout on their healer: %.0fs summed, %.0fs of it distinct, %.0f%% overlapped\n", $sum, $union, $sum ? 100 * (1 - $union / $sum) : 0);

// Attempts anchored on an event rather than cut by time: each stretch their healer was locked, and
// what the --from buttons did in it. A window is lockout by us, joined across --join seconds, at
// least --min long. Ready means alive, free and off cooldown (kit cooldowns only) when it began.
if ($opt('windows', null) !== null && ($from = $opt('from', null))) {
    $join = (float) $opt('join', '1');
    $min = (float) $opt('min', '3');
    $lead = (float) $opt('lead', '1.5');
    $buttons = collect($sections)->flatMap(fn ($s) => $s['steps'])->filter(fn ($s) => in_array($s['spell'], explode(',', $from), true))->unique('spell')->values();
    $tally = ['won' => ['rounds' => 0, 'windows' => 0, 'used' => 0, 'none' => 0, 'noneReady' => 0, 'readyUnused' => 0, 'pressed' => 0, 'outside' => 0], 'lost' => ['rounds' => 0, 'windows' => 0, 'used' => 0, 'none' => 0, 'noneReady' => 0, 'readyUnused' => 0, 'pressed' => 0, 'outside' => 0]];
    foreach ($matched as $m) {
        $a = $m['a'];
        $key = $a['won'] ? 'won' : 'lost';
        $tally[$key]['rounds']++;
        $ours = collect($a['players'])->where('side', 'us')->keyBy(fn ($p) => (int) $p['specExternalId']);
        $healer = collect($a['players'])->first(fn ($p) => $p['side'] === 'them' && ! empty($p['healer']))['guid'] ?? null;
        $windows = [];
        foreach (collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[0] === $healer && $ours->pluck('guid')->contains($c[1]) && in_array($c[3], LOCKOUT, true))->sortBy(fn ($c) => $c[4]) as $c) {
            $l = array_key_last($windows);
            if ($l !== null && $c[4] - $windows[$l][1] <= $join) {
                $windows[$l][1] = max($windows[$l][1], $c[5]);
            } else {
                $windows[] = [$c[4], $c[5]];
            }
        }
        $windows = array_values(array_filter($windows, fn ($w) => $w[1] - $w[0] >= $min));
        $died = collect($a['deaths'] ?? [])->groupBy('who')->map(fn ($d) => (float) $d->min('t'));
        $inside = [];
        foreach ($windows as [$s, $e]) {
            $tally[$key]['windows']++;
            $used = 0;
            $readyUnused = 0;
            foreach ($buttons as $b) {
                $p = $ours[$b['spec']] ?? null;
                if (! $p) {
                    continue;
                }
                $casts = collect($a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => $c[1] === $b['spell'])->pluck(0);
                $in = $casts->filter(fn ($t) => $t >= $s - $lead && $t <= $e);
                foreach ($in as $t) {
                    $inside[$b['spell'].$t] = true;
                }
                if ($in->isNotEmpty()) {
                    $used++;

                    continue;
                }
                $kit = collect($p['kit'] ?? [])->firstWhere('name', $b['spell']);
                $cd = (float) ($kit['cd'] ?? 0);
                $back = $casts->filter(fn ($t) => $t < $s)->max();
                $off = $cd > 0 && ($back === null || $back + $cd <= $s);
                if ($off && ! (($died[$p['guid']] ?? INF) <= $s) && ! $lockedAt($a, $p['guid'], $s)) {
                    $readyUnused++;
                }
            }
            $tally[$key]['readyUnused'] += $readyUnused;
            if ($used) {
                $tally[$key]['used']++;
            } elseif ($readyUnused) {
                $tally[$key]['none']++;
            } else {
                $tally[$key]['noneReady']++;
            }
        }
        foreach ($buttons as $b) {
            $p = $ours[$b['spec']] ?? null;
            foreach ($p ? collect($a['timeline']['casts'][$p['guid']] ?? [])->filter(fn ($c) => $c[1] === $b['spell'])->pluck(0) : [] as $t) {
                $tally[$key]['pressed']++;
                $tally[$key]['outside'] += isset($inside[$b['spell'].$t]) ? 0 : 1;
            }
        }
    }
    printf("\nEach stretch their healer was locked (joined %.1fs, %.1fs or longer, %.1fs lead): %s\n", $join, $min, $lead, $from);
    foreach ($tally as $key => $t) {
        printf("  %-5s rounds %3d windows %3d (%.1f a round)  a button pressed %3d (%2d%%)  none pressed, one ready %3d (%2d%%)  none ready %3d (%2d%%)  presses outside any window %3d of %3d (%2d%%)\n", $key, $t['rounds'], $t['windows'], $t['rounds'] ? $t['windows'] / $t['rounds'] : 0, $t['used'], $t['windows'] ? 100 * $t['used'] / $t['windows'] : 0, $t['none'], $t['windows'] ? 100 * $t['none'] / $t['windows'] : 0, $t['noneReady'], $t['windows'] ? 100 * $t['noneReady'] / $t['windows'] : 0, $t['outside'], $t['pressed'], $t['pressed'] ? 100 * $t['outside'] / $t['pressed'] : 0);
    }
}

// One section read against another's control, split by result.
if ($link[0] !== '') {
    $goSection = collect($sections)->first(fn ($s) => str_contains($s['title'], $link[0])) ?? exit("\nNo section named {$link[0]}\n");
    $tally = ['won' => ['held' => 0, 'soon' => 0, 'free' => 0, 'n' => 0, 'rounds' => 0], 'lost' => ['held' => 0, 'soon' => 0, 'free' => 0, 'n' => 0, 'rounds' => 0]];
    foreach ($matched as $m) {
        $key = $m['a']['won'] ? 'won' : 'lost';
        $tally[$key]['rounds']++;
        foreach ($read($m['a'], $goSection) as $attempt) {
            // The first press that does damage, when --from names those: Tiger's Fury is setup.
            $first = $attempt['from'];
            if ($from = $opt('from', null)) {
                $hits = array_filter($attempt['presses'], fn ($p) => in_array($goSection['steps'][$p['step']]['spell'], explode(',', $from), true));
                if (! $hits) {
                    continue;
                }
                $first = reset($hits)['t'];
            }
            $healer = $healerAt($m['a'], $first);
            $tally[$key]['n']++;
            if ($healer['state'] === 'held') {
                $tally[$key]['held']++;
            } elseif (($healer['next'] ?? 99) <= 3) {
                $tally[$key]['soon']++;
            } else {
                $tally[$key]['free']++;
            }
        }
    }
    printf("\n%s: their healer at the first press\n", $goSection['title']);
    foreach ($tally as $key => $t) {
        printf("  %-5s rounds %3d attempts %3d  held %3d (%2d%%)  control within 3s %3d (%2d%%)  free %3d (%2d%%)\n", $key, $t['rounds'], $t['n'], $t['held'], $t['n'] ? 100 * $t['held'] / $t['n'] : 0, $t['soon'], $t['n'] ? 100 * $t['soon'] / $t['n'] : 0, $t['free'], $t['n'] ? 100 * $t['free'] / $t['n'] : 0);
    }
}
