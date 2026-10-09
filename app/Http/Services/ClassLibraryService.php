<?php

namespace App\Http\Services;

use App\Console\Commands\BuildPopulation;
use App\Models\ArenaRound;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\User;
use App\Quiz\Wow\WowAbilityFacts;
use App\Support\DesktopAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * The desktop app's Classes page (tools/log-manager), asked for by Chriso on 2026-10-07: for every
 * spec you have played against, the highest-rated and the most experienced player of it you met,
 * and a page for each with what they press, set against the median player of the spec and against
 * you when you play it too.
 *
 *  - Highest rated: the highest team MMR you met them at (3v3 and 2v2; a Solo Shuffle round has none).
 *  - Most experienced: the most Gladiator seasons on their Blizzard profile, then Rank 1 titles,
 *    then best 3v3 rating ever (PlayerExperienceService, as the cards show it).
 *
 * Each page reads every round you played against that player, on any character, from what
 * RoundAnalysisService stored (presses need version 10, `habits`). The spec's medians come from
 * data/population/norms.json (wow:population). Presses are per minute free to act, the same basis
 * as the norms, so a player stunned for half the game is not read as slow. It describes what a
 * strong player of the spec did in your games, which is a level to compare with, not a rotation to
 * copy: the log cannot show their talents' reasons, their positioning or their calls
 * (docs/learning/population-findings-2026-10-07-top-tier.md: the top's style is the entry price,
 * not what wins).
 */
class ClassLibraryService
{
    /** The groups a pressed spell is shown in, in order, by its role in the spec's kit. */
    private const GROUPS = [
        'rotation' => 'Rotation and fillers',
        'offensive' => 'Offensive cooldowns',
        'defensive' => 'Defensives',
        'control' => 'Crowd control',
        'interrupt' => 'Interrupts',
        'movement' => 'Movement',
        'other' => 'Other: procs, racials, trinkets and spells few of the spec press',
        'pet' => 'Pet and summons',
    ];

    /** Rows shown per group; the rest are pressed too rarely to read. */
    private const GROUP_ROWS = ['rotation' => 14, 'other' => 8, 'pet' => 6];

    /** More casts a minute than one a second (a hasted global cooldown): the game cast it, not the player. */
    private const FASTEST_PRESS = 60;

    /** Under this many minutes free to act, a player's presses a minute are not shown. */
    private const MIN_FREE_MINUTES = 1.0;

    private array $xp = [];

    private ?array $norms = null;

    /** @var array<int, array<string, string>> spec id => spell name => role */
    private array $roles = [];

    public function __construct(private CompLibraryService $comps) {}

    public function signature(User $user, array $xp = []): string
    {
        $rounds = ArenaRound::where('user_id', $user->id)->orderBy('id')->get(['id', 'updated_at'])
            ->map(fn ($r) => $r->id.':'.$r->updated_at)->implode(',');
        $files = [__FILE__, resource_path('views/desktop/player.blade.php'), resource_path('views/desktop/partials/styles.blade.php'), base_path(BuildPopulation::NORMS)];
        $code = implode('|', array_map(fn ($f) => $f.':'.(File::exists($f) ? filemtime($f) : 0), $files))
            .'|spells:'.app(TalentSelectionService::class)->spellCacheVersion();
        $who = md5(json_encode(array_map(fn ($x) => [$x['gladSeasons'] ?? null, $x['exp3v3'] ?? null, $x['rankOneSeasons'] ?? null], $xp)));

        return md5($rounds.'|'.$who.'|'.$code);
    }

    /**
     * @return array<string, array{name: string, spec: string, specId: int, class: string, classSlug: ?string,
     *     color: string, picks: array, games: int, won: int, lost: int, last: string, html: string}>
     */
    public function build(User $user, array $xp = []): array
    {
        $this->xp = $xp;
        $met = [];      // full name => their rounds
        $mine = [];     // spec id => the logging player's own rounds of that spec
        foreach (ArenaRound::where('user_id', $user->id)->orderBy('played_at')->get() as $r) {
            $payload = $r->payload ?? [];
            $a = $payload['analysis'] ?? null;
            if (! $a || empty($a['players'])) {
                continue;
            }
            foreach ($a['players'] as $p) {
                if ($p['side'] === 'them' && ($p['specExternalId'] ?? 0) > 0) {
                    $met[$p['name']][] = ['r' => $r, 'a' => $a, 'p' => $p];
                } elseif (! empty($p['logger']) && ($p['specExternalId'] ?? 0) > 0) {
                    $mine[$p['specExternalId']][] = ['r' => $r, 'a' => $a, 'p' => $p];
                }
            }
        }

        $out = [];
        foreach ($this->picks($met) as $full => $picks) {
            $rounds = collect($met[$full]);
            $p = $rounds->last()['p'];
            $model = $this->model($full, $rounds, $picks, collect($mine[$p['specExternalId']] ?? []));
            $won = $rounds->filter(fn ($x) => $x['a']['won'])->count();
            $out[$full] = [
                'name' => $model['name'], 'spec' => $model['spec'], 'specId' => $model['specId'],
                'class' => $model['class'], 'classSlug' => $p['classSlug'] ?? null, 'color' => $model['color'],
                'picks' => $picks, 'why' => $this->why($full, $picks), 'games' => $rounds->count(), 'won' => $won, 'lost' => $rounds->count() - $won,
                'last' => (string) $rounds->last()['r']->played_at,
                'html' => view('desktop.player', ['m' => $model])->render(),
            ];
        }

        return $out;
    }

    /**
     * For each spec met: the player you met at the highest team MMR, and the one with the most
     * experience on their profile. One player can be both.
     *
     * @return array<string, array{rated: ?array, experienced: ?string}>
     */
    private function picks(array $met): array
    {
        $bySpec = [];
        foreach ($met as $full => $rounds) {
            $bySpec[$rounds[0]['p']['specExternalId']][] = $full;
        }

        $picks = [];
        foreach ($bySpec as $names) {
            $rated = collect($names)->map(function ($full) use ($met) {
                $best = collect($met[$full])->filter(fn ($x) => isset($x['a']['mmr']['them']) && $x['a']['mmr']['them'] > 0)
                    ->sortByDesc(fn ($x) => $x['a']['mmr']['them'])->first();

                return $best ? ['full' => $full, 'mmr' => (int) $best['a']['mmr']['them'], 'bracket' => $best['r']->bracket] : null;
            })->filter()->sortByDesc(fn ($x) => $x['mmr'] * 100 + ($this->xp[$x['full']]['gladSeasons'] ?? 0))->first();

            $experienced = collect($names)->filter(fn ($full) => $this->xp[$full]['found'] ?? false)
                ->sortByDesc(fn ($full) => sprintf('%03d%03d%05d', $this->xp[$full]['gladSeasons'] ?? 0, $this->xp[$full]['rankOneSeasons'] ?? 0, $this->xp[$full]['exp3v3'] ?? 0))
                ->first();

            if ($rated) {
                $picks[$rated['full']]['rated'] = ['mmr' => $rated['mmr'], 'bracket' => str_replace('Rated ', '', (string) $rated['bracket'])];
            }
            if ($experienced) {
                $picks[$experienced]['experienced'] = $this->xpText($experienced);
            }
        }

        return array_map(fn ($p) => $p + ['rated' => null, 'experienced' => null], $picks);
    }

    private function model(string $full, Collection $rounds, array $picks, Collection $mine): array
    {
        $p = $rounds->last()['p'];
        $specId = (int) $p['specExternalId'];
        $spec = Specialization::with('gameClass')->where('external_spec_id', $specId)->first();
        $norm = $this->norms()['specs'][(string) $specId] ?? null;
        $healer = (bool) $p['healer'];
        $won = $rounds->filter(fn ($x) => $x['a']['won'])->count();

        $them = $this->totals($rounds);
        $you = $this->totals($mine);

        return [
            'name' => explode('-', $full)[0],
            'realm' => implode('-', array_slice(explode('-', $full), 1)),
            'spec' => $p['spec'],
            'specId' => $specId,
            'class' => $spec?->gameClass?->name ?? '',
            'color' => config('wow_classes.colors')[$p['classSlug'] ?? ''] ?? '#8A8A9A',
            'icon' => $spec?->icon_name ? DesktopAsset::url('spec-icons/'.$spec->icon_name) : null,
            'healer' => $healer,
            'xp' => $this->xpText($full),
            'glad' => ($this->xp[$full]['gladSeasons'] ?? 0) > 0,
            'picks' => $picks,
            'record' => [$won, $rounds->count() - $won],
            'from' => substr((string) $rounds->first()['r']->played_at, 0, 10),
            'to' => substr((string) $rounds->last()['r']->played_at, 0, 10),
            'byCharacter' => $rounds->groupBy(fn ($x) => collect($x['a']['players'])->firstWhere('logger', true)['name'] ?? '?')
                ->map(fn ($xs, $name) => ['name' => explode('-', $name)[0], 'won' => $xs->filter(fn ($x) => $x['a']['won'])->count(), 'lost' => $xs->reject(fn ($x) => $x['a']['won'])->count()])
                ->values()->all(),
            'measured' => $them['rounds'],
            'freeMinutes' => round($them['free'] / 60, 1),
            'youPlay' => $you['free'] / 60 >= self::MIN_FREE_MINUTES ? ['rounds' => $you['rounds'], 'minutes' => round($you['free'] / 60, 1)] : null,
            'norm' => $norm ? ['rounds' => $norm['rounds'], 'players' => $norm['players'], 'label' => $norm['label']] : null,
            'presses' => $this->presses($specId, $them, $you, $norm),
            'output' => $this->output($them, $you, $norm, $healer),
            'goes' => $this->goes($rounds),
            'defensives' => $this->defensives($rounds),
            'kickedOf' => $this->comps->withIcons(collect($them['kicked'])->sortDesc()->take(6)->map(fn ($n, $s) => ['label' => $s, 'value' => $n.'×'])->values()->all()),
            'breakdown' => $this->breakdown($them, $healer),
            'list' => $rounds->sortByDesc(fn ($x) => (string) $x['r']->played_at)->map(fn ($x) => [
                'when' => $x['r']->played_at?->format('D j M, H:i'),
                'bracket' => str_replace('Rated ', '', (string) $x['r']->bracket),
                'who' => explode('-', collect($x['a']['players'])->firstWhere('logger', true)['name'] ?? '?')[0],
                'won' => $x['a']['won'],
                'mmr' => isset($x['a']['mmr']['us'], $x['a']['mmr']['them']) ? $x['a']['mmr']['us'].' / '.$x['a']['mmr']['them'] : null,
                'died' => ($d = collect($x['a']['deaths'])->firstWhere('who', $x['p']['guid'])) ? $this->clock($d['t']) : null,
            ])->values()->all(),
        ];
    }

    /** One player's rounds summed: presses, time, output, as the habits and breakdown stored them. */
    private function totals(Collection $rounds): array
    {
        $t = ['rounds' => 0, 'free' => 0.0, 'alive' => 0.0, 'idle' => 0.0, 'locked' => 0.0, 'casts' => [],
            'kicks' => 0, 'control' => 0, 'offTarget' => 0, 'kicked' => [], 'damage' => [], 'healing' => [], 'diedFirst' => 0];
        foreach ($rounds as $x) {
            $g = $x['p']['guid'];
            $h = $x['a']['habits'][$g] ?? null;
            $b = $x['a']['breakdown'][$g] ?? null;
            if (! $h || ! $b) {
                continue;   // measured before version 10
            }
            $t['rounds']++;
            $t['free'] += $h['free'] ?? 0;
            $t['alive'] += $b['alive'] ?? 0;
            $t['idle'] += $b['idle'] ?? 0;
            $t['locked'] += $x['a']['lockout'][$g] ?? 0;
            $t['kicks'] += $h['kicks'] ?? 0;
            $t['control'] += $h['control'] ?? 0;
            $t['offTarget'] += $h['offTarget'] ?? 0;
            $t['diedFirst'] += ($x['a']['deaths'][0]['who'] ?? null) === $g ? 1 : 0;
            foreach ($h['casts'] ?? [] as $s => $n) {
                $t['casts'][$s] = ($t['casts'][$s] ?? 0) + $n;
            }
            foreach ($h['kicked'] ?? [] as $s => $n) {
                $t['kicked'][$s] = ($t['kicked'][$s] ?? 0) + $n;
            }
            foreach (['damage', 'healing', 'absorbs'] as $kind) {
                foreach ($b[$kind] ?? [] as $row) {
                    $into = $kind === 'absorbs' ? 'healing' : $kind;
                    $t[$into][$row['spell']] = ($t[$into][$row['spell']] ?? 0) + $row['amount'];
                }
            }
        }

        return $t;
    }

    /**
     * Every button they pressed, a minute free to act, grouped by its role in the spec's kit, beside
     * the median player of the spec (among those who pressed it) and beside you.
     */
    private function presses(int $specId, array $them, array $you, ?array $norm): array
    {
        $minutes = $them['free'] / 60;
        if ($minutes < self::MIN_FREE_MINUTES) {
            return [];
        }
        $yourMinutes = $you['free'] / 60;
        $roles = $this->roles($specId);

        $groups = [];
        foreach ($them['casts'] as $name => $n) {
            $median = $norm['abilities'][$name] ?? null;
            if ($n < 2 && ! $median) {
                continue;   // one press in all their games says nothing
            }
            $rate = $n / $minutes;
            // Faster than one a second is not a hand on a key: a proc the
            // log writes as a cast (Soul Fragment, hundreds a game).
            $role = match (true) {
                str_starts_with($name, 'pet: ') => 'pet',
                $rate > self::FASTEST_PRESS => 'other',
                default => $roles[$name] ?? $this->fallbackRole($name, $median),
            };
            $groups[$role][] = [
                'label' => $name,
                'theirs' => $rate,
                'total' => $n,
                'median' => $median['p50'] ?? null,
                'used' => $median['used'] ?? null,
                'yours' => $yourMinutes >= self::MIN_FREE_MINUTES ? ($you['casts'][$name] ?? 0) / $yourMinutes : null,
            ];
        }

        $out = [];
        foreach (self::GROUPS as $role => $title) {
            $rows = collect($groups[$role] ?? [])->sortByDesc('theirs')->take(self::GROUP_ROWS[$role] ?? 10)->values();
            if ($rows->isEmpty()) {
                continue;
            }
            $max = max(0.01, $rows->max(fn ($r) => max($r['theirs'], $r['median'] ?? 0, $r['yours'] ?? 0)));
            $rows = $rows->map(fn ($r) => $r + [
                'bar' => round(100 * $r['theirs'] / $max),
                'barMedian' => $r['median'] !== null ? round(100 * $r['median'] / $max) : null,
                // Only a gap worth reading: half again, or under two thirds, of a median most of the spec has.
                'vs' => $r['median'] && ($r['used'] ?? 0) >= 0.5 && $r['median'] >= 0.2
                    ? ($r['theirs'] >= 1.5 * $r['median'] ? 'more' : ($r['theirs'] <= 0.66 * $r['median'] ? 'less' : null)) : null,
            ])->all();
            $out[] = ['role' => $role, 'title' => $title, 'rows' => $this->comps->withIcons(array_map(fn ($r) => ['label' => preg_replace('/^pet: /', '', $r['label'])] + $r, $rows))];
        }

        return $out;
    }

    /** Their output and habits against the spec's median and yours. */
    private function output(array $them, array $you, ?array $norm, bool $healer): array
    {
        $perFree = fn (array $t, float $v) => $t['free'] >= 60 ? $v / ($t['free'] / 60) : null;
        $share = fn (array $t, float $v) => $t['alive'] >= 60 ? $v / $t['alive'] : null;
        $sum = fn (array $t, string $k) => (float) array_sum($t[$k]);
        $rows = [];
        $add = function (string $label, ?float $theirs, ?float $median, ?float $yours, string $format) use (&$rows) {
            if ($theirs === null) {
                return;
            }
            $f = match ($format) {
                'big' => fn ($v) => $v >= 1e6 ? round($v / 1e6, 2).'M' : round($v / 1e3).'k',
                'pct' => fn ($v) => round($v * 100).'%',
                default => fn ($v) => number_format($v, 2),
            };
            $rows[] = ['label' => $label, 'theirs' => $f($theirs), 'median' => $median !== null ? $f($median) : '-', 'yours' => $yours !== null ? $f($yours) : '-'];
        };

        if ($healer) {
            $add('Healing and absorbs a minute free to act', $perFree($them, $sum($them, 'healing')), $norm['healingPerFreeMinute']['p50'] ?? null, $perFree($you, $sum($you, 'healing')), 'big');
        }
        $add('Damage a minute free to act', $perFree($them, $sum($them, 'damage')), $norm['damagePerFreeMinute']['p50'] ?? null, $perFree($you, $sum($you, 'damage')), 'big');
        $add('Time not pressing anything', $share($them, $them['idle']), $norm['idleShare']['p50'] ?? null, $share($you, $you['idle']), 'pct');
        $add('Time crowd-controlled', $share($them, $them['locked']), $norm['lockedShare']['p50'] ?? null, $share($you, $you['locked']), 'pct');
        $add('Kicks a minute free', $perFree($them, $them['kicks']), $norm['kicksPerMinute']['p50'] ?? null, $perFree($you, $you['kicks']), 'num');
        $add('Crowd control and kicks landed, a minute free', $perFree($them, $them['control']), $norm['controlPerMinute']['p50'] ?? null, $perFree($you, $you['control']), 'num');
        $add('Of those, on someone other than their damage target', $them['control'] >= 3 ? $them['offTarget'] / $them['control'] : null,
            $norm['offTargetShare']['p50'] ?? null, $you['control'] >= 3 ? $you['offTarget'] / $you['control'] : null, 'pct');

        return $rows;
    }

    /** Their part in their team's goes: the offensive cooldowns they pressed, and their crowd control on you. */
    private function goes(Collection $rounds): array
    {
        $goes = $rounds->flatMap(fn ($x) => collect($x['a']['goes'])->where('side', 'them')->map(fn ($go) => $go + ['_g' => $x['p']['guid']]))->values();
        $mine = fn ($go, array $cats) => collect($go['links'] ?? [])->filter(fn ($l) => ($l['by'] ?? null) === $go['_g'] && in_array($l['cat'], $cats, true));
        $pressed = $goes->filter(fn ($go) => $mine($go, ['offensive', 'mixed'])->isNotEmpty())->count();
        $offensive = $goes->flatMap(fn ($go) => $mine($go, ['offensive', 'mixed'])->pluck('spell')->unique())->countBy()->sortDesc();
        $roleText = ['healer' => 'your healer', 'target' => 'their kill target', 'cross' => 'your other DPS'];
        $control = $goes->flatMap(fn ($go) => $mine($go, ['control'])->map(fn ($l) => ['spell' => $l['spell'], 'on' => $roleText[$l['role'] ?? ''] ?? 'one of you']))
            ->groupBy('spell')->sortByDesc(fn ($ls) => $ls->count());
        $pct = fn (int $n) => $goes->count() ? round(100 * $n / $goes->count()).'%' : '-';

        return [
            'count' => $goes->count(),
            'pressedIn' => $pct($pressed),
            'killed' => $pct($goes->filter(fn ($go) => $go['kill'] || $go['killLater'])->count()),
            'offensive' => $this->comps->withIcons($offensive->take(6)->map(fn ($n, $s) => ['label' => $s, 'value' => $pct($n).' of goes'])->values()->all()),
            // Each spell once, with whom it landed on: "Fear 12×: your other DPS 5, your healer 4, ...".
            'control' => $this->comps->withIcons($control->take(8)->map(fn ($ls, $spell) => [
                'label' => $spell,
                'value' => $ls->count().'×: '.$ls->countBy('on')->sortDesc()->map(fn ($n, $on) => "{$on} {$n}")->implode(', '),
            ])->values()->all()),
        ];
    }

    /** The defensives they pressed: how often, at what health, and how many with nothing coming. */
    private function defensives(Collection $rounds): array
    {
        $rows = $rounds->flatMap(fn ($x) => collect($x['a']['defensives']['them']['rows'] ?? [])->where('who', $x['p']['guid']))->values();
        $median = fn (Collection $v) => $v->isEmpty() ? null : (int) round($v->sort()->values()->median());

        return [
            'count' => $rows->count(),
            'perRound' => $rounds->count() ? round($rows->count() / $rounds->count(), 1) : 0,
            'hp' => $median($rows->pluck('hp')->filter(fn ($v) => is_numeric($v))),
            'outside' => $rows->where('outside', true)->count(),
            'rows' => $this->comps->withIcons($rows->groupBy('spell')->map(function ($rs, $spell) use ($median) {
                $hp = $median($rs->pluck('hp')->filter(fn ($v) => is_numeric($v)));

                return ['label' => $spell, 'value' => $rs->count().'×'.($hp !== null ? ', at '.$hp.'% health' : ''), 'n' => $rs->count()];
            })->sortByDesc('n')->take(8)->values()->all()),
        ];
    }

    /** Their damage and healing by ability, as shares, like the card's Damage & healing tab. */
    private function breakdown(array $them, bool $healer): array
    {
        $out = [];
        foreach ($healer ? ['healing' => 'Healing and absorbs', 'damage' => 'Damage'] : ['damage' => 'Damage', 'healing' => 'Healing and absorbs'] as $kind => $title) {
            $total = array_sum($them[$kind]);
            if ($total <= 0 || ($kind === 'healing' && ! $healer && $total < 0.05 * max(1, array_sum($them['damage'])))) {
                continue;
            }
            arsort($them[$kind]);
            $rows = [];
            foreach (array_slice($them[$kind], 0, 10, true) as $spell => $amount) {
                $rows[] = ['label' => preg_replace('/^pet: /', '', $spell), 'share' => round(100 * $amount / $total), 'pet' => str_starts_with($spell, 'pet: ')];
            }
            $out[] = ['title' => $title, 'rows' => $this->comps->withIcons(array_map(fn ($r) => $r + ['value' => $r['share'].'%'], $rows))];
        }

        return $out;
    }

    /**
     * Each spell the spec's kit holds, by its role: crowd control, an interrupt, a defensive, an
     * offensive cooldown, movement, or the rotation. Names the kit does not hold (procs, racials,
     * trinkets) are left to the caller as "other".
     *
     * @return array<string, string>
     */
    private function roles(int $specId): array
    {
        if (isset($this->roles[$specId])) {
            return $this->roles[$specId];
        }
        $spec = Specialization::with('gameClass')->where('external_spec_id', $specId)->first();
        $path = $spec ? base_path("data/spell-kits/{$spec->gameClass?->slug}/{$spec->slug}.json") : null;
        $entries = $path && File::exists($path) ? collect(json_decode(File::get($path), true)['entries'] ?? [])->keyBy('spellId') : collect();
        $rank = ['movement' => 7, 'control' => 6, 'interrupt' => 5, 'defensive' => 4, 'offensive' => 3, 'rotation' => 1];

        $roles = [];
        foreach (Spell::whereIn('id', $entries->keys())->get(['id', 'name', 'spell_id', 'is_passive', 'is_interrupt', 'is_mobility']) as $s) {
            if (in_array($s->spell_id, WowAbilityFacts::UNIVERSAL_SPELL_IDS, true)) {
                continue;
            }
            $e = $entries[$s->id];
            $off = $e['offensiveDefensive']['offensive'] ?? false;
            $def = $e['offensiveDefensive']['defensive'] ?? false;
            // A gap closer that roots or stuns on arrival is movement first (Wild Charge: "it moves
            // YOU to THEM", cc-synergies-overrides.txt).
            $role = match (true) {
                (bool) $s->is_mobility => 'movement',
                $e['drCategory'] !== null => 'control',
                (bool) $s->is_interrupt => 'interrupt',
                $def && ! $off => 'defensive',
                $off => 'offensive',
                (bool) $s->is_passive => null,
                default => 'rotation',
            };
            // One name can be several copies; the most specific role wins (Storm Bolt is control).
            $name = $s->display_name;
            if ($role !== null && $rank[$role] > ($rank[$roles[$name] ?? ''] ?? 0)) {
                $roles[$name] = $role;
            }
        }

        return $this->roles[$specId] = $roles;
    }

    /** @var array<string, ?string> a role by spell name, for names the spec's kit does not hold */
    private array $fallback = [];

    private ?array $classification = null;

    /**
     * A spell the spec's kit does not hold: a baseline spell the kit leaves out (rule 1's ambiguous
     * class-wide rows: Incinerate), the Medallion, a racial, a proc. Its role from the promoted
     * classification and the spell data by name; failing those, the rotation when most players of
     * the spec press it, else "other".
     */
    private function fallbackRole(string $name, ?array $median): string
    {
        if (! array_key_exists($name, $this->fallback)) {
            $this->classification ??= app(ArenaLogService::class)->offensiveDefensiveClassification()['byName'];
            $c = $this->classification[$name] ?? null;
            $copies = Spell::query()->where('patch_id', Patch::where('is_current', true)->value('id'))->where('name', $name)
                ->get(['dr_category', 'is_interrupt', 'is_mobility']);
            $this->fallback[$name] = match (true) {
                $c !== null && $c['defensive'] && ! $c['offensive'] => 'defensive',
                $c !== null && $c['offensive'] => 'offensive',
                $copies->contains(fn ($s) => (bool) $s->is_mobility) => 'movement',
                $copies->contains(fn ($s) => $s->dr_category !== null) => 'control',
                $copies->contains(fn ($s) => (bool) $s->is_interrupt) => 'interrupt',
                default => null,
            };
        }

        return $this->fallback[$name] ?? (($median['used'] ?? 0) >= 0.5 ? 'rotation' : 'other');
    }

    /** Why a player is listed, short enough for the app's list: "2309 3v3 MMR · 8× Glad, best 2121". */
    private function why(string $full, array $picks): string
    {
        $x = $this->xp[$full] ?? [];
        $xp = ($x['found'] ?? false) ? implode(', ', array_filter([
            ($x['gladSeasons'] ?? 0) > 0 ? $x['gladSeasons'].'× Glad' : null,
            ($x['rankOneSeasons'] ?? 0) > 0 ? $x['rankOneSeasons'].'× R1' : null,
            'best '.($x['exp3v3'] ?? '?'),
        ])) : null;

        return implode(' · ', array_filter([
            $picks['rated'] ? $picks['rated']['mmr'].' '.$picks['rated']['bracket'].' MMR' : null,
            $xp,
        ]));
    }

    private function xpText(string $full): string
    {
        $x = $this->xp[$full] ?? null;

        return match (true) {
            $x === null => 'not looked up',
            ! ($x['found'] ?? false) => 'no public profile',
            default => implode(' · ', array_filter([
                ($x['gladSeasons'] ?? 0) > 0 ? $x['gladSeasons'].'× Gladiator' : null,
                ($x['rankOneSeasons'] ?? 0) > 0 ? $x['rankOneSeasons'].'× Rank 1' : null,
                'best 3v3 '.($x['exp3v3'] ?? '?'),
                $x['bestRank'] ?? null,
            ])),
        };
    }

    private function norms(): array
    {
        $path = base_path(BuildPopulation::NORMS);

        return $this->norms ??= File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
    }

    private function clock(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }
}
