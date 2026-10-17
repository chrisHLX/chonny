<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\User;
use App\Support\DefensiveFit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "What to work on", per character, for the desktop app's Improve page (tools/log-manager).
 *
 * Each of the player's characters (the logging character of each stored round) is measured on the
 * habits the match reviews found mattered (match-review-analysis.md, 2 Oct): dispels against the
 * chance to dispel, crowd control on their healer inside the team's goes, the team's big
 * defensives when the enemy's go started, defensives spent outside the enemy's goes, time locked
 * out, and dying first. Each measure is set beside every OTHER player of the same spec in the same
 * stored games, both teams, so "behind" means behind the players you actually meet.
 *
 * Built only from what RoundAnalysisService stored, like GameCardService. Dispels and the defensive
 * cover need analysis version 6 (2026-10-03); a round stored earlier simply does not count toward
 * them, and the page says how many rounds each measure rests on.
 *
 * It describes; it does not judge. A measure is "behind" when it is more than GAP worse than the
 * others' and both sides rest on at least LEAD_BELOW rounds. Whether a dispel is worth a global, or
 * a fear belonged on the healer, is the player's call, and the page says so.
 */
class ImprovementService
{
    /** Relative difference before a measure reads as ahead of or behind the others. */
    private const GAP = 0.2;

    /** Fewer rounds than this on either side and a difference is a lead, not a finding. */
    private const LEAD_BELOW = MatchAnalysisService::LEAD_BELOW;

    /** The newest rounds, compared with everything before them, for a trend. */
    private const RECENT = 20;

    /** A session (a day played) needs this many games to count toward the usual range... */
    private const SESSION_MIN = 3;

    /** ...and the range needs this many such sessions before the last one is read against it. */
    private const SESSIONS_FOR_RANGE = 3;

    /** Dispels on a long cooldown that would make "could have dispelled" unfair (Cyclone via Mass Dispel). */
    private const NOT_A_ROUTINE_DISPEL = ['Mass Dispel'];

    /** What a game produces rather than something done in it: listed after the habits, focus last. */
    private const OUTCOMES = ['damage', 'healing'];

    /** "In N of your last 15": the deaths a pattern is read over. */
    private const LAST_DEATHS = 15;

    /** Seconds of dispellable debuffs on the team before a dispel rate is read at all. */
    private const DISPEL_MIN_SECONDS = 60;

    /** The spell data's effect type that makes a spell a dispel (Purify, Cleanse, Nature's Cure). */
    private const DISPEL_EFFECT = 'Dispel (38)';

    /** @var array<string, true> the spells that count as a dispel, by name */
    private array $dispelSpells = [];

    public function __construct(private SpellIconIndex $icons) {}

    /**
     * Rounds and code the pages are drawn from, so the command can skip an unchanged build.
     */
    public function signature(User $user, array $xp = []): string
    {
        $rounds = ArenaRound::where('user_id', $user->id)->orderBy('id')->get(['id', 'updated_at'])
            ->map(fn ($r) => $r->id.':'.$r->updated_at)->implode(',');
        // Experience moves each game's difficulty, so it moves the page.
        $rounds .= '|'.md5(json_encode(array_map(fn ($x) => $x['gladSeasons'] ?? null, $xp)));
        $code = implode('|', array_map(fn ($f) => $f.':'.filemtime($f), [__FILE__, resource_path('views/desktop/improve.blade.php'),
            resource_path('views/desktop/partials/styles.blade.php'), resource_path('views/desktop/partials/spark.blade.php'),
            app_path('Support/DefensiveFit.php'), DefensiveFit::path()]));
        // Which spells dispel comes from the spell data, so a re-import can move the page.
        $code .= '|spells:'.app(TalentSelectionService::class)->spellCacheVersion();

        return md5($rounds.$code);
    }

    /**
     * @return array<string, array{name: string, spec: string, games: int, html: string}> by full character name
     */
    /** @var array<string, array> experience by full name, for each game's difficulty */
    private array $xp = [];

    public function build(User $user, array $xp = []): array
    {
        $this->xp = $xp;
        $rounds = ArenaRound::where('user_id', $user->id)->orderBy('played_at')->get()
            ->map(function (ArenaRound $r) {
                // Decoded once: Eloquent's array cast decodes the whole payload on every access.
                $payload = $r->payload ?? [];

                return [
                    'at' => (string) $r->played_at,
                    'mins' => max(0.1, (float) ($payload['metadata']['durationInSeconds'] ?? 0) / 60),
                    'a' => $payload['analysis'] ?? null,
                    // Each player's damage taken (CombatantThroughputService), for healing against it.
                    'taken' => array_map(fn ($p) => (int) ($p['damageTaken'] ?? 0), $payload['throughput']['players'] ?? []),
                ];
            })
            ->filter(fn ($r) => $r['a'] !== null && ! empty($r['a']['players']))
            ->values();

        $this->dispelSpells = $this->dispelSpells();
        $dispelSets = $this->dispelSets($rounds);

        // One row per (round, player): every player in every stored round, both teams.
        $rows = [];
        foreach ($rounds as $r) {
            foreach ($r['a']['players'] as $p) {
                $rows[] = $this->measure($r, $p, $dispelSets[$p['spec']] ?? []);
            }
        }
        $rows = collect($rows);

        $out = [];
        foreach ($rows->where('you', true)->groupBy('full') as $full => $mine) {
            $spec = $mine->countBy('spec')->sortDesc()->keys()->first();
            $mine = $mine->where('spec', $spec)->values();
            $others = $rows->where('spec', $spec)->where('you', false)->where('full', '!=', $full)->values();
            $model = $this->model($full, $spec, $mine, $others, $dispelSets[$spec] ?? []);
            $out[$full] = [
                'name' => $model['name'],
                'spec' => $spec,
                'games' => $mine->count(),
                'html' => view('desktop.improve', ['m' => $model])->render(),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ measuring

    /**
     * The spells that count as a dispel: those whose spell data carries a Dispel effect (Purify,
     * Cleanse, Remove Corruption, Detox), less the long-cooldown ones.
     *
     * The combat log records a removal for more than a dispel. Phantasm strips a slow when a Priest
     * fades; a Druid's shapeshift breaks a root; Blessing of Freedom; Cleanse the Weak's extra
     * removals, which come free with one Cleanse. Counted as dispels (to 4 Oct 2026), a Feral's
     * shapeshifts were 115 of its 133 "dispels". The debuffs only those remove (Crippling Poison,
     * Chains of Ice: 16 of the 91 on a Disc Priest's list) read as chances to dispel that no dispel
     * could take.
     *
     * @return array<string, true>
     */
    private function dispelSpells(): array
    {
        $names = Spell::query()
            ->where('patch_id', Patch::where('is_current', true)->value('id'))
            ->whereHas('effects', fn ($q) => $q->where('type', self::DISPEL_EFFECT))
            ->whereNotIn('name', self::NOT_A_ROUTINE_DISPEL)
            ->pluck('name')
            ->all();

        return array_fill_keys($names, true);
    }

    /**
     * The same sets for another reader (GameMomentsService), from a list of stored analyses: which
     * debuffs each spec's dispels were seen removing. One definition of "could have been dispelled".
     *
     * @return array<string, array<string, true>>
     */
    public function dispellable(array $analyses): array
    {
        $this->dispelSpells = $this->dispelSpells ?: $this->dispelSpells();

        return $this->dispelSets(collect($analyses)->map(fn ($a) => ['a' => $a]));
    }

    /**
     * For each spec, the debuffs its players' dispels were seen removing, across every stored round.
     *
     * @return array<string, array<string, true>>
     */
    private function dispelSets(Collection $rounds): array
    {
        $sets = [];
        foreach ($rounds as $r) {
            $specOf = collect($r['a']['players'])->pluck('spec', 'guid');
            foreach ($r['a']['dispels'] ?? [] as $d) {
                if (isset($this->dispelSpells[$d['spell']]) && isset($specOf[$d['by']])) {
                    $sets[$specOf[$d['by']]][$d['removed']] = true;
                }
            }
        }

        return $sets;
    }

    /** One player's measures in one round. */
    private function measure(array $r, array $p, array $dispellable): array
    {
        $a = $r['a'];
        $guid = $p['guid'];
        $side = $p['side'];
        $team = collect($a['players'])->where('side', $side)->pluck('guid')->all();
        $v6 = ($a['version'] ?? 0) >= 6;

        // The chance to dispel: seconds each teammate carried one of the spec's dispellable
        // debuffs, merged per teammate, summed over the team.
        $opp = 0.0;
        $carried = [];
        if ($v6 && $dispellable) {
            foreach ($team as $mate) {
                $iv = [];
                foreach ($a['debuffs'][$mate] ?? [] as [$name, $from, $to]) {
                    if (isset($dispellable[$name])) {
                        $iv[] = [$from, $to];
                        $carried[$name] = ($carried[$name] ?? 0) + ($to - $from);
                    }
                }
                $opp += $this->len($this->union($iv));
            }
        }
        $removed = $v6 ? collect($a['dispels'] ?? [])->where('by', $guid)->filter(fn ($d) => isset($this->dispelSpells[$d['spell']]))->count() : 0;

        $teamGoes = collect($a['goes'])->where('side', $side);
        $theirGoes = collect($a['goes'])->where('side', '!=', $side);
        $ccGoes = $teamGoes->filter(fn ($g) => in_array($guid, $g['healerCcBy'] ?? [], true));
        $ccSpells = [];
        foreach ($teamGoes as $g) {
            foreach ($g['links'] ?? [] as $l) {
                if (($l['by'] ?? null) === $guid && ($l['cat'] ?? null) === 'control' && ($l['role'] ?? null) === 'healer') {
                    $ccSpells[$l['spell']] = ($ccSpells[$l['spell']] ?? 0) + 1;
                }
            }
        }

        // Output by ability (RoundAnalysisService `breakdown`, version 5 on): damage onto the other
        // team's players, and healing plus absorbs onto your own, over the time you were alive.
        $b = $a['breakdown'][$guid] ?? null;
        $sum = function (array $rows) {
            $out = [];
            foreach ($rows as $row) {
                $out[$row['spell']] = ($out[$row['spell']] ?? 0) + $row['amount'];
            }

            return $out;
        };
        $healBy = $b ? $sum(array_merge($b['healing'], $b['absorbs'])) : [];

        $deaths = collect($a['deaths'])->sortBy('t');
        $teamFirst = $deaths->first(fn ($d) => $d['side'] === $side);
        $won = $side === 'us' ? $a['won'] : ! $a['won'];
        $defs = collect($a['defensives'][$side]['rows'] ?? [])->where('who', $guid);

        return [
            'full' => $p['name'],
            'spec' => $p['spec'],
            'classSlug' => $p['classSlug'] ?? null,
            'healer' => (bool) $p['healer'],
            'you' => (bool) $p['logger'],
            // How hard the game was from this player's side (GameCardService::difficultyOf).
            'difficulty' => GameCardService::difficultyOf(
                (int) ($side === 'us' ? ($a['mmr']['them'] ?? 0) - ($a['mmr']['us'] ?? 0) : ($a['mmr']['us'] ?? 0) - ($a['mmr']['them'] ?? 0)),
                isset($a['mmr']['us'], $a['mmr']['them']),
                (int) collect($a['players'])->where('side', '!=', $side)->sum(fn ($q) => $this->xp[$q['name']]['gladSeasons'] ?? 0),
                (int) collect($a['players'])->where('side', $side)->sum(fn ($q) => $this->xp[$q['name']]['gladSeasons'] ?? 0),
            )['label'],
            // Each round's result comes from its deaths (rule 12), so a side's result holds for
            // every player on it, a shuffle round included.
            'won' => $won,
            'at' => $r['at'],
            'mins' => $r['mins'],
            'v6' => $v6,
            'opp' => $opp,
            'removed' => $removed,
            'carried' => $carried,
            'teamGoes' => $teamGoes->count(),
            'ccGoes' => $ccGoes->count(),
            'ccGoesKilled' => $ccGoes->filter(fn ($g) => $g['kill'] || $g['killLater'])->count(),
            'goesKilled' => $teamGoes->filter(fn ($g) => $g['kill'] || $g['killLater'])->count(),
            'ccSpells' => $ccSpells,
            // Their goes, by how many of this team's big defensives were down as each started.
            'cover' => $theirGoes->filter(fn ($g) => isset($g['cover']))->map(fn ($g) => ['bigDown' => $g['cover']['bigDown'], 'kill' => $g['kill'] || $g['killLater']])->values()->all(),
            'hasBreakdown' => $b !== null,
            'alive' => (float) ($b['alive'] ?? 0),
            'idle' => (float) ($b['idle'] ?? 0),
            'dmgBy' => $b ? $sum($b['damage']) : [],
            'healBy' => $healBy,
            'teamTaken' => array_sum(array_map(fn ($g) => $r['taken'][$g] ?? 0, $team)),
            'defs' => $defs->count(),
            // Version 9: pressed with its target in danger, or to break crowd control.
            'defsNeeded' => $defs->filter(fn ($d) => ! empty($d['needed']))->count(),
            'defsWarranted' => $defs->filter(fn ($d) => array_key_exists('needed', $d))->count(),
            'defsOutside' => $defs->where('outside', true)->count(),
            'lockout' => (float) ($a['lockout'][$guid] ?? 0),
            // Version 10 (`habits`): time free to act, your casts that were kicked, and, for the
            // player who logged the game only (WoW writes no one else's), casts that failed for line
            // of sight.
            'v10' => ($a['version'] ?? 0) >= 10 && isset($a['habits'][$guid]),
            'free' => (float) ($a['habits'][$guid]['free'] ?? 0),
            'kicked' => array_sum($a['habits'][$guid]['kicked'] ?? []),
            // Version 15: the high-priority casts met with your kick ready, what you stopped, and
            // which casts went through. Null without an interrupt or before version 15.
            'prio' => isset($a['habits'][$guid]['priority']) ? [
                'chances' => (int) $a['habits'][$guid]['priority']['high']['chances'],
                'mine' => (int) $a['habits'][$guid]['priority']['high']['mine'],
                'through' => $a['habits'][$guid]['priority']['through'] ?? [],
            ] : null,
            'losFails' => $p['logger'] ? (int) ($a['habits'][$guid]['failed']['Target not in line of sight']['n'] ?? 0) : null,
            // Your team's first death with an answer sheet (version 7, our side only), and your own
            // defensives that sat ready through the go that killed AND could have saved the player
            // who died (DefensiveFit: reached them, covers that damage). Counted only when you had a
            // second or more free of lockout in it: a button you could not press was not left.
            'sheet' => $sheet = isset($teamFirst['answers']['rows']),
            'readyFit' => $sheet && (($teamFirst['t'] - $teamFirst['answers']['from']) - ($teamFirst['answers']['lockout'][$guid]['seconds'] ?? 0)) >= 1
                ? collect($teamFirst['answers']['rows'])
                    ->filter(fn ($row) => $row['who'] === $guid && $row['state'] === 'ready' && in_array($row['kind'], ['defensive', 'trinket'], true)
                        && DefensiveFit::judge($row['spell'], $teamFirst['who'] === $guid, $teamFirst['schools'] ?? null)['fit'] === 'fits')
                    ->pluck('spell')->unique()->values()->all()
                : [],
            'diedFirst' => $teamFirst !== null && $teamFirst['who'] === $guid && ! $won,
            'teamLost' => $teamFirst !== null && ! $won,
            // The one Medallion fault the log supports: a teammate died while you were locked out
            // with your Medallion ready (not pressed in the 120s before). Healers only.
            'medallionFault' => $p['healer'] && $teamFirst && $teamFirst['who'] !== $guid
                && ($teamFirst['healer']['state'] ?? null) === 'locked'
                && collect($teamFirst['healer']['medallionUsedAt'] ?? [])->filter(fn ($t) => $t <= $teamFirst['t'] && $teamFirst['t'] - $t < 120)->isEmpty(),
            // Presses per ability (version 10), for the rotation and the "why" under damage and healing.
            'presses' => $a['habits'][$guid]['casts'] ?? [],
            // Version 16: what the "why" reads from the round's timeline. Null before it.
            'v16' => ($a['version'] ?? 0) >= 16 && isset($a['timeline']) ? $this->timelineReads($a, $guid, $side, $team, $dispellable, (bool) $p['healer']) : null,
        ];
    }

    /**
     * One player's round read off its timeline (version 16), for the "why" lines and the rotation:
     *  - chance: seconds a teammate carried a debuff the spec dispels, the part of it the player was
     *    locked out, and what they cast in the rest (by name);
     *  - idle: seconds alive, free and pressing nothing (gaps over 2.5s), split by whose go it fell in;
     *  - opening: what a healer cast in the first seconds of each of the other team's goes;
     *  - ccMissed: the team's goes without their control on the enemy healer, and how many of those
     *    they spent locked out as it started.
     */
    private function timelineReads(array $a, string $guid, string $side, array $team, array $dispellable, bool $healer): array
    {
        $tl = $a['timeline'];
        $casts = $tl['casts'][$guid] ?? [];
        $locked = [];
        foreach ($tl['control'] as [$on, , , $dr, $from, $to]) {
            if ($on === $guid && in_array($dr, ['Stun', 'Silence', 'Disorient', 'Incapacitate'], true)) {
                $locked[] = [$from, $to];
            }
        }
        $locked = $this->union($locked);
        $lockedIn = fn (float $f, float $t) => array_sum(array_map(fn ($iv) => max(0, min($t, $iv[1]) - max($f, $iv[0])), $locked));

        // The chance to dispel, and what filled it.
        $windows = [];
        if ($dispellable) {
            foreach ($team as $mate) {
                foreach ($a['debuffs'][$mate] ?? [] as [$name, $from, $to]) {
                    if (isset($dispellable[$name])) {
                        $windows[] = [$from, $to];
                    }
                }
            }
        }
        $windows = $this->union($windows);
        $chanceLocked = 0.0;
        $chanceCasts = [];
        foreach ($windows as [$f, $t]) {
            $chanceLocked += $lockedIn($f, $t);
            foreach ($casts as [$ct, $spell]) {
                if ($ct >= $f && $ct <= $t) {
                    $chanceCasts[$spell] = ($chanceCasts[$spell] ?? 0) + 1;
                }
            }
        }

        // Time pressing nothing, by whose go it fell in.
        $goes = collect($a['goes']);
        $theirs = $this->union($goes->where('side', '!=', $side)->map(fn ($g) => [(float) $g['from'], (float) $g['to']])->values()->all());
        $ours = $this->union($goes->where('side', $side)->map(fn ($g) => [(float) $g['from'], (float) $g['to']])->values()->all());
        $alive = count($tl['hp'][$guid] ?? []);
        foreach ($tl['hp'][$guid] ?? [] as $i => $h) {
            if ($h === 0 && $i > 0) {
                $alive = $i;
                break;
            }
        }
        $idle = ['theirs' => 0.0, 'ours' => 0.0, 'none' => 0.0];
        $times = array_merge([0.0], array_column($casts, 0), [(float) $alive]);
        sort($times);
        for ($i = 1; $i < count($times); $i++) {
            [$f, $t] = [$times[$i - 1], min($times[$i], (float) $alive)];
            if ($t - $f <= 2.5) {
                continue;
            }
            $gap = $t - $f - $lockedIn($f, $t);
            $inTheirs = array_sum(array_map(fn ($iv) => max(0, min($t, $iv[1]) - max($f, $iv[0])), $theirs));
            $inOurs = array_sum(array_map(fn ($iv) => max(0, min($t, $iv[1]) - max($f, $iv[0])), $ours));
            $span = max(0.001, $t - $f);
            $idle['theirs'] += $gap * min(1, $inTheirs / $span);
            $idle['ours'] += $gap * min(1, $inOurs / $span);
            $idle['none'] += $gap * max(0, 1 - ($inTheirs + $inOurs) / $span);
        }

        // A healer's opening casts in the other team's goes.
        $opening = [];
        if ($healer) {
            foreach ($goes->where('side', '!=', $side) as $g) {
                foreach ($casts as [$ct, $spell]) {
                    if ($ct >= (float) $g['from'] && $ct <= (float) $g['from'] + 4) {
                        $opening[$spell] = ($opening[$spell] ?? 0) + 1;
                    }
                }
            }
        }

        // Your team's goes without your control on their healer.
        $missed = $goes->where('side', $side)->reject(fn ($g) => in_array($guid, $g['healerCcBy'] ?? [], true));

        return [
            'chance' => array_sum(array_map(fn ($w) => $w[1] - $w[0], $windows)),
            'chanceLocked' => $chanceLocked,
            'chanceCasts' => $chanceCasts,
            'idle' => $idle,
            'opening' => $opening,
            'openingGoes' => $healer ? $goes->where('side', '!=', $side)->count() : 0,
            'ccMissed' => $missed->count(),
            'ccMissedLocked' => $missed->filter(fn ($g) => $lockedIn((float) $g['from'], (float) $g['from'] + 3) >= 1.0)->count(),
        ];
    }

    // ------------------------------------------------------------------ the page

    private function model(string $full, string $spec, Collection $mine, Collection $others, array $dispellable): array
    {
        $recent = $mine->count() >= self::RECENT + 10 ? $mine->slice(-self::RECENT) : null;
        $earlier = $recent ? $mine->slice(0, $mine->count() - self::RECENT) : null;
        // Sessions: the days this character played, oldest first. The last is the coach's "measure
        // it again next session"; see session() for what it is set against.
        $sessions = $mine->groupBy(fn ($r) => substr($r['at'], 0, 10))->sortKeys();
        $beforeLast = $mine->reject(fn ($r) => str_starts_with($r['at'], (string) $sessions->keys()->last()))->values();
        $trend = fn (callable $value) => [
            'recent' => $recent ? $value($recent) : null,
            'earlier' => $recent ? $value($earlier) : null,
            'n' => $recent ? self::RECENT : null,
            'series' => $sessions->map(fn ($rs, $day) => ['day' => $day, 'v' => $value($rs->values()), 'n' => $rs->count()])->values()->all(),
            'beforeLast' => $beforeLast->isNotEmpty() ? $value($beforeLast) : null,
        ];

        $habits = [];

        // 1. Dispels, per minute your team carried something you could have taken off.
        if ($dispellable && $mine->where('v6', true)->isNotEmpty()) {
            // Under a minute of chances, a rate says nothing: two dispels on a session's few seconds
            // of poison read as 15 a minute (a Feral, 26 Sep).
            $rate = fn (Collection $rs) => ($o = $rs->where('v6', true)->sum('opp')) >= self::DISPEL_MIN_SECONDS ? $rs->where('v6', true)->sum('removed') / ($o / 60) : null;
            $perMin = fn (Collection $rs) => ($m = $rs->where('v6', true)->sum('mins')) > 0 ? $rs->where('v6', true)->sum('opp') / $m : null;
            $carried = [];
            foreach ($mine->where('v6', true) as $r) {
                foreach ($r['carried'] as $name => $s) {
                    $carried[$name] = ($carried[$name] ?? 0) + $s;
                }
            }
            arsort($carried);
            $games = max(1, $mine->where('v6', true)->count());
            $habits[] = $this->habit('dispels', 'Dispels', true,
                'Debuffs you dispelled off your team, per minute your team carried one that '.$this->plural($spec).' were seen dispelling.',
                $rate($mine), $rate($others), $mine->where('v6', true)->count(), $others->where('v6', true)->count(), 2, '',
                [
                    sprintf('Your team carried one for %s a minute (%s for the others).', $this->secs($perMin($mine)), $this->secs($perMin($others))),
                ],
                $trend($rate),
                'Only a dispel counts, not a shapeshift or Phantasm breaking a slow. Whether a dispel is worth a global is your call: it costs a heal, and some debuffs punish the dispeller (Unstable Affliction).',
                $this->iconRows(array_map(fn ($s) => $this->secs($s / $games).' a game', array_slice($carried, 0, 6, true)), 'Left on your team most'),
            );
        }

        // 2. Crowd control on their healer inside your team's goes.
        $share = fn (Collection $rs) => ($g = $rs->sum('teamGoes')) > 0 ? $rs->sum('ccGoes') / $g : null;
        if ($mine->sum('ccGoes') + $others->sum('ccGoes') > 0) {
            $with = $mine->sum('ccGoes');
            $without = $mine->sum('teamGoes') - $with;
            $spells = [];
            foreach ($mine as $r) {
                foreach ($r['ccSpells'] as $s => $n) {
                    $spells[$s] = ($spells[$s] ?? 0) + $n;
                }
            }
            arsort($spells);
            $habits[] = $this->habit('cc', 'Crowd control on their healer', true,
                "Your team's goes with your crowd control on their healer.",
                $share($mine), $share($others), $mine->count(), $others->count(), 0, '%',
                [
                    sprintf('Your goes with it led to a kill %s, without it %s.',
                        $this->pctOf($mine->sum('ccGoesKilled'), $with), $this->pctOf($mine->sum('goesKilled') - $mine->sum('ccGoesKilled'), $without)),
                ],
                $trend($share),
                'A go converts when the burst lands while their healer is locked out, not merely when they are CC\'d at some point in it.',
                $this->iconRows(array_map(fn ($n) => $n.'×', array_slice($spells, 0, 5, true)), 'What you put on their healer'),
            );
        }

        // 3. Your team's big defensives as their go started (the cooldown ledger).
        $cover = $mine->where('v6', true)->flatMap(fn ($r) => $r['cover']);
        if ($cover->isNotEmpty()) {
            $bucket = fn ($lo, $hi) => $cover->filter(fn ($c) => $c['bigDown'] >= $lo && $c['bigDown'] <= $hi);
            $twoPlus = fn (Collection $rs) => ($c = $rs->where('v6', true)->flatMap(fn ($r) => $r['cover']))->isNotEmpty() ? $c->where('bigDown', '>=', 2)->count() / $c->count() : null;
            $habits[] = $this->habit('cover', 'Big defensives when their go started', false,
                "Their goes that started with two or more of your team's big defensives (90s+) still on cooldown.",
                $twoPlus($mine), $twoPlus($others), $mine->where('v6', true)->count(), $others->where('v6', true)->count(), 0, '%',
                [
                    'Their go killed one of you: '
                    .'none down '.$this->pctOf($bucket(0, 0)->where('kill', true)->count(), $bucket(0, 0)->count())
                    .', one down '.$this->pctOf($bucket(1, 1)->where('kill', true)->count(), $bucket(1, 1)->count())
                    .', two or more '.$this->pctOf($bucket(2, 99)->where('kill', true)->count(), $bucket(2, 99)->count()).'.',
                ],
                $trend($twoPlus),
                'With two big defensives down, play for time until they come back instead of starting a fight. This counts the whole team\'s defensives, and the log doesn\'t say who each one was used on.',
            );
        }

        // 4. Defensives spent while they were not in a go.
        $outside = fn (Collection $rs) => ($d = $rs->sum('defs')) > 0 ? $rs->sum('defsOutside') / $d : null;
        if ($mine->sum('defs') > 0) {
            $lines = [];
            $needed = fn (Collection $rs) => ($n = $rs->sum('defsWarranted')) > 0 ? $rs->sum('defsNeeded') / $n : null;
            if ($mine->sum('defsWarranted') > 0) {
                $lines[] = sprintf('Pressed with the target in danger or to break crowd control: %s of yours, %s of theirs. The log can\'t tell us why the rest were pressed.',
                    $this->pct($needed($mine)), $this->pct($needed($others)));
            }
            if ($mine->first()['healer']) {
                $faults = $mine->where('medallionFault', true)->count();
                $lines[] = sprintf('A teammate died while you were locked out with your Medallion ready: %d time%s in %d games.', $faults, $faults === 1 ? '' : 's', $mine->count());
            }
            $habits[] = $this->habit('defensives', 'Defensives outside their goes', false,
                'Your defensives pressed while the other team was not in a go, before the first death.',
                $outside($mine), $outside($others), $mine->count(), $others->count(), 0, '%', $lines, $trend($outside),
                'A defensive used to break crowd control outside a go may still have stopped one starting; the log cannot show that.',
            );
        }

        // 5. Time locked out.
        $lock = fn (Collection $rs) => ($m = $rs->sum('mins')) > 0 ? $rs->sum('lockout') / $m : null;
        $habits[] = $this->habit('lockout', 'Time locked out', false,
            'Seconds a minute you spent stunned, silenced, disoriented or incapacitated.',
            $lock($mine), $lock($others), $mine->count(), $others->count(), 1, 's',
            [sprintf('In your wins %s, in your losses %s.', $this->num($lock($mine->where('won', true)), 1, 's'), $this->num($lock($mine->where('won', false)), 1, 's'))],
            $trend($lock),
            'Stronger teams lock you out more, so part of this is who you played. Where you stand is the part the log cannot see.',
        );

        // 6. Dying first, for a damage dealer.
        if (! $mine->first()['healer']) {
            $first = fn (Collection $rs) => ($l = $rs->where('teamLost', true)->count()) > 0 ? $rs->where('diedFirst', true)->count() / $l : null;
            $habits[] = $this->habit('died', 'Dying first', false,
                "Your team's losses that began with you dying.",
                $first($mine), $first($others), $mine->where('teamLost', true)->count(), $others->where('teamLost', true)->count(), 0, '%',
                [], $trend($first),
                'The big defensive pressed when their cooldowns go out, not at the bottom of your health, was the clearest fix on 30 Sep.',
            );
        }

        // 7. Damage onto their players, per minute alive, and what it is made of.
        $withB = fn (Collection $rs) => $rs->where('hasBreakdown', true);
        if ($withB($mine)->isNotEmpty()) {
            $dpm = fn (Collection $rs) => ($s = $withB($rs)->sum('alive')) > 0 ? $withB($rs)->sum(fn ($r) => array_sum($r['dmgBy'])) / ($s / 60) / 1000 : null;
            $habits[] = $this->habit('damage', 'Damage', true,
                "Your damage onto the other team's players, per minute you were alive.",
                $dpm($mine), $dpm($others), $withB($mine)->count(), $withB($others)->count(), 0, 'k',
                [], $trend($dpm),
                'Damage depends on your comp and on whom you play: per minute alive takes game length out, not those. A finishing blow counts in full.',
                $this->mixRows($withB($mine), $withB($others), 'dmgBy', 'What it is made of, against other '.$this->plural($spec)),
            );
        }

        // 8. Healing, for a healer: against the damage your team took, because raw healing rises with
        // the damage there is to heal.
        if ($mine->first()['healer'] && $withB($mine)->isNotEmpty()) {
            $cover = fn (Collection $rs) => ($t = $withB($rs)->sum('teamTaken')) > 0 ? $withB($rs)->sum(fn ($r) => array_sum($r['healBy'])) / $t : null;
            $hpm = fn (Collection $rs) => ($s = $withB($rs)->sum('alive')) > 0 ? $withB($rs)->sum(fn ($r) => array_sum($r['healBy'])) / ($s / 60) / 1000 : null;
            $habits[] = $this->habit('healing', 'Healing', true,
                'Your healing and absorbs onto your team, as a share of the damage your team took.',
                $cover($mine), $cover($others), $withB($mine)->count(), $withB($others)->count(), 0, '%',
                [sprintf('%s a minute alive (%s for the others). Overhealing is not counted.', $this->num($hpm($mine), 0, 'k'), $this->num($hpm($others), 0, 'k'))],
                $trend($cover),
                'Over 100% is normal: healing lands on damage from before, and some damage taken is never meant to be healed.',
                $this->mixRows($withB($mine), $withB($others), 'healBy', 'What it is made of, against other '.$this->plural($spec)),
            );
        }

        // 9. Time alive and free, pressing nothing.
        if ($withB($mine)->isNotEmpty()) {
            $idle = fn (Collection $rs) => ($s = $withB($rs)->sum('alive')) > 0 ? $withB($rs)->sum('idle') / $s : null;
            $habits[] = $this->habit('idle', 'Time not pressing anything', false,
                'Your time alive in gaps of more than 2.5s between casts, not counting time locked out.',
                $idle($mine), $idle($others), $withB($mine)->count(), $withB($others)->count(), 0, '%',
                [], $trend($idle),
                'A cast longer than 2.5s counts as a gap, and so does waiting out of sight on purpose.',
            );
        }

        // 10. Your casts kicked, per minute free to act (version 10). Rounds with many casts
        // interrupted won less often across the whole archive (wow:population, `castsInterrupted`).
        $v10 = fn (Collection $rs) => $rs->where('v10', true);
        if ($v10($mine)->isNotEmpty()) {
            $kicked = fn (Collection $rs) => ($f = $v10($rs)->sum('free')) > 0 ? $v10($rs)->sum('kicked') / ($f / 60) : null;
            $habits[] = $this->habit('kicked', 'Your casts kicked', false,
                'Your casts that were interrupted, per minute you were alive and free to act.',
                $kicked($mine), $kicked($others), $v10($mine)->count(), $v10($others)->count(), 2, '',
                [], $trend($kicked),
                'Casting into a ready kick is the common cause; a cast started to draw the kick out and stopped is the answer. Fewer casts also means fewer kicks, so read it beside your damage.',
            );

            // 11. Casts that failed for line of sight (yours only: WoW logs no one else's failures).
            $los = fn (Collection $rs) => ($f = $v10($rs)->where('you', true)->sum('free')) > 0 ? $v10($rs)->where('you', true)->sum('losFails') / ($f / 60) : null;
            $habits[] = $this->habit('los', 'Casts blocked by line of sight', false,
                'Your casts that failed because the target was out of sight, per minute free to act. Only yours: WoW logs no other player\'s failed casts.',
                $los($mine), null, $v10($mine)->count(), 0, 2, '',
                [], $trend($los),
                'Across the archive, rounds with many of these were won less often. A pillar between you and the target blocks heals and control alike: move before you press.',
            );
        }

        // 12. The casts worth a kick (data/kick-priorities, high) met with your kick ready, and the
        // share you stopped (version 15). The other players of your spec are everyone the archive
        // met, so the comparison is against the ladder, not your teammates.
        $prio = fn (Collection $rs) => $rs->filter(fn ($r) => $r['prio'] !== null);
        if ($prio($mine)->sum(fn ($r) => $r['prio']['chances']) > 0) {
            $share = fn (Collection $rs) => ($c = $prio($rs)->sum(fn ($r) => $r['prio']['chances'])) > 0 ? $prio($rs)->sum(fn ($r) => $r['prio']['mine']) / $c : null;
            $through = $prio($mine)->flatMap(fn ($r) => collect($r['prio']['through'])->map(fn ($n, $s) => ['s' => $s, 'n' => $n])->values())
                ->groupBy('s')->map(fn ($g) => $g->sum('n'))->sortDesc();
            $habits[] = $this->habit('priorityKicks', 'High-priority casts you kicked', true,
                'Of the casts top players rank highest to interrupt (crowd control, the big heals and cooldowns), the share you stopped when one began while you were alive, free to act, had your kick ready and stood within its range.',
                $share($mine), $share($others), $prio($mine)->count(), $prio($others)->count(), 0, '%',
                [sprintf('%d chances in your games, %d stopped.', $prio($mine)->sum(fn ($r) => $r['prio']['chances']), $prio($mine)->sum(fn ($r) => $r['prio']['mine']))],
                $trend($share),
                'Line of sight is not in the log, so a pillar may have been in the way, and a teammate may have had the kick. Each spec\'s list is on its PvP guide on the website.',
                $this->iconRows(array_map(fn ($n) => $n.' went through', $through->take(6)->all()), 'Went through most often'),
            );
        }

        // 13. Your team's deaths with one of your defensives ready that could have saved them: the one
        // death on a game card, counted across games so a pattern shows. Other players of the spec
        // count only where they were on your team, which is rarely enough to grade against.
        $sheets = fn (Collection $rs) => $rs->where('sheet', true);
        if ($sheets($mine)->isNotEmpty()) {
            $left = fn (Collection $rs) => ($n = $sheets($rs)->count()) > 0 ? $sheets($rs)->filter(fn ($r) => $r['readyFit'])->count() / $n : null;
            $last = $sheets($mine)->slice(-self::LAST_DEATHS);
            $spells = $sheets($mine)->flatMap(fn ($r) => $r['readyFit'])->countBy()->sortDesc();
            $habits[] = $this->habit('ready', 'Deaths with a fitting defensive of yours ready', false,
                "Your team's first death in a round, with one of your defensives ready and never pressed that could have saved them: it reached them and covers the damage that killed. Counted only when you had time free to press it.",
                $left($mine), $left($others), $sheets($mine)->count(), $sheets($others)->count(), 0, '%',
                [sprintf('In %d of your last %d.', $last->filter(fn ($r) => $r['readyFit'])->count(), $last->count())],
                $trend($left),
                'It lists what was there, not what would have won: one kept for the go after can be the right hold. Each game card shows the go beside it.',
                $this->iconRows(array_map(fn ($n) => $n.' of '.$sheets($mine)->count(), $spells->take(6)->all()), 'Ready most often'),
            );
        }

        // Where a measure is behind, why: the gap split into the parts the rounds can show (why()).
        foreach ($habits as &$h) {
            $h['why'] = $h['status'] === 'behind' ? $this->why($h['key'], $mine, $others) : [];
        }
        unset($h);

        // Behind the others first, then level, then ahead; within each, habits before outcomes (the
        // focus's own rule, so the list never leads with what the focus skips), then the larger gap.
        $order = ['behind' => 0, 'lead' => 1, 'level' => 2, 'ahead' => 3, 'none' => 4];
        $outcome = fn (array $h) => (int) in_array($h['key'], self::OUTCOMES, true);
        usort($habits, fn ($x, $y) => [$order[$x['status']], $outcome($x), -abs($x['gap'] ?? 0)] <=> [$order[$y['status']], $outcome($y), -abs($y['gap'] ?? 0)]);

        $won = $mine->where('won', true)->count();

        return [
            'name' => explode('-', $full)[0],
            'full' => $full,
            'spec' => $spec,
            'color' => config('wow_classes.colors')[$mine->first()['classSlug'] ?? ''] ?? '#C8952C',
            'games' => $mine->count(),
            'record' => [$won, $mine->count() - $won],
            'from' => substr($mine->first()['at'], 0, 10),
            'to' => substr($mine->last()['at'], 0, 10),
            'others' => $others->pluck('full')->unique()->count(),
            'otherGames' => $others->count(),
            'v6' => $mine->where('v6', true)->count(),
            // Your record against harder, even and easier teams: a loss to a team well above you
            // is not the same evidence as one at even odds.
            'byDifficulty' => collect(['Harder', 'Even', 'Easier'])->mapWithKeys(fn ($label) => [$label => [
                $mine->where('difficulty', $label)->where('won', true)->count(),
                $mine->where('difficulty', $label)->where('won', false)->count(),
            ]])->filter(fn ($wl) => array_sum($wl) > 0)->all(),
            'habits' => $habits,
            'rotation' => $this->rotation($spec, $mine, $others),
            'focus' => ($focus = $this->focus($habits, (bool) $mine->first()['healer']))
                ? $focus + ['chartWide' => $this->spark($focus['series'], $focus['ref'], 300, 64)]
                : null,
        ];
    }

    // ------------------------------------------------------------------ why, and the rotation

    /**
     * Why a measure is behind (2026-10-17): the gap split into what the rounds can show, each line a
     * fact with both sides' numbers. It says where the difference comes from, never that it is a
     * fault (match-review.md, reading rules 1 and 6), and a line needs enough on both sides to stand.
     *
     * @return array<int, string>
     */
    private function why(string $key, Collection $mine, Collection $others): array
    {
        $withB = fn (Collection $rs) => $rs->where('hasBreakdown', true);
        $v16 = fn (Collection $rs) => $rs->filter(fn ($r) => $r['v16'] !== null);

        return match ($key) {
            'damage' => array_merge($this->byAbility($withB($mine), $withB($others), 'dmgBy'), $this->activeLine($withB($mine), $withB($others))),
            'healing' => array_merge($this->pressureLine($withB($mine), $withB($others)), $this->byAbility($withB($mine), $withB($others), 'healBy')),
            'dispels' => $v16($mine)->count() >= 3 && $v16($others)->count() >= 3 ? $this->dispelWhy($v16($mine), $v16($others)) : [],
            'idle' => $v16($mine)->count() >= 3 && $v16($others)->count() >= 3 ? $this->idleWhy($v16($mine), $v16($others)) : [],
            'cc' => $v16($mine)->count() >= 3 ? $this->ccWhy($v16($mine), $v16($others)) : [],
            default => [],
        };
    }

    /**
     * Per ability, per minute alive, the three where the others do most more, and whether it is how
     * often it is pressed or how much each press does.
     */
    private function byAbility(Collection $mine, Collection $others, string $field): array
    {
        $rates = function (Collection $rs) use ($field) {
            $alive = max(1, $rs->sum('alive')) / 60;
            $free = max(1, $rs->where('v10', true)->sum('free')) / 60;
            $amount = [];
            $presses = [];
            foreach ($rs as $r) {
                foreach ($r[$field] as $spell => $v) {
                    $amount[$spell] = ($amount[$spell] ?? 0) + $v;
                }
                if ($r['v10']) {
                    foreach ($r['presses'] as $spell => $n) {
                        $presses[$spell] = ($presses[$spell] ?? 0) + $n;
                    }
                }
            }

            return collect($amount)->map(fn ($v, $spell) => [
                'perMin' => $v / $alive / 1000,
                'presses' => isset($presses[$spell]) ? $presses[$spell] / $free : null,
                'perPress' => ! empty($presses[$spell]) ? $v / $presses[$spell] / 1000 : null,
            ]);
        };
        $a = $rates($mine);
        $b = $rates($others);

        return $b->map(fn ($x, $spell) => $x + ['gap' => $x['perMin'] - ($a[$spell]['perMin'] ?? 0)])
            ->filter(fn ($x) => $x['gap'] > 0 && $x['perMin'] >= 10)
            ->sortByDesc('gap')->take(3)
            ->map(function ($x, $spell) use ($a) {
                $y = $a[$spell] ?? ['perMin' => 0, 'presses' => null, 'perPress' => null];
                $line = sprintf('%s: %s a minute against %s for them.', $spell, $this->num($y['perMin'], 0, 'k'), $this->num($x['perMin'], 0, 'k'));
                if ($y['presses'] !== null && $x['presses'] !== null && $x['presses'] > 0) {
                    if ($y['presses'] <= 0.8 * $x['presses']) {
                        $line .= sprintf(' You press it %s times a free minute, they press it %s.', $this->num($y['presses'], 1, ''), $this->num($x['presses'], 1, ''));
                    } elseif ($y['perPress'] !== null && $x['perPress'] !== null && $y['perPress'] <= 0.8 * $x['perPress']) {
                        $line .= sprintf(' You press it about as often, but each press does %s against %s: talents, gear or what it lands on.', $this->num($y['perPress'], 0, 'k'), $this->num($x['perPress'], 0, 'k'));
                    }
                } elseif ($y['perMin'] == 0) {
                    $line .= ' You did not use it in these games.';
                }

                return $line;
            })->values()->all();
    }

    /** Time alive spent free and pressing something: locked out and idle both take from it. */
    private function activeLine(Collection $mine, Collection $others): array
    {
        $active = fn (Collection $rs) => ($s = $rs->where('v10', true)->sum('alive')) > 0
            ? ($rs->where('v10', true)->sum('free') - $rs->where('v10', true)->sum('idle')) / $s : null;
        [$x, $y] = [$active($mine), $active($others)];
        if ($x === null || $y === null || abs($x - $y) < 0.05) {
            return [];
        }

        return [sprintf('You were free and pressing something %s of your time alive, they %s: time locked out and time idle both take from your damage.', $this->pct($x), $this->pct($y))];
    }

    /** How much damage there was to heal: healing as a share of it moves with it. */
    private function pressureLine(Collection $mine, Collection $others): array
    {
        $taken = fn (Collection $rs) => ($m = $rs->sum('mins')) > 0 ? $rs->sum('teamTaken') / $m / 1000 : null;
        [$x, $y] = [$taken($mine), $taken($others)];
        if ($x === null || $y === null) {
            return [];
        }

        return [sprintf('Your team took %s a minute, theirs %s. %s', $this->num($x, 0, 'k'), $this->num($y, 0, 'k'),
            $x > $y * 1.1 ? 'More damage to heal brings the share down even at the same healing.' : ($x < $y * 0.9 ? 'Less damage to heal, so this gap is not about how hard it was.' : 'About the same pressure, so the gap is in the healing.'))];
    }

    /** Locked out while something was dispellable, and what the free time went on. */
    private function dispelWhy(Collection $mine, Collection $others): array
    {
        $agg = function (Collection $rs) {
            $chance = $rs->sum(fn ($r) => $r['v16']['chance']);
            $casts = [];
            foreach ($rs as $r) {
                foreach ($r['v16']['chanceCasts'] as $s => $n) {
                    $casts[$s] = ($casts[$s] ?? 0) + $n;
                }
            }
            arsort($casts);
            $total = max(1, array_sum($casts));
            $dispels = array_sum(array_intersect_key($casts, $this->dispelSpells));

            return ['locked' => $chance > 0 ? $rs->sum(fn ($r) => $r['v16']['chanceLocked']) / $chance : null, 'casts' => $casts, 'total' => $total, 'dispelShare' => $dispels / $total];
        };
        $a = $agg($mine);
        $b = $agg($others);
        $out = [];
        if ($a['locked'] !== null && $b['locked'] !== null) {
            $out[] = sprintf('While a debuff you could dispel was on your team, you were locked out %s of the time, they %s.', $this->pct($a['locked']), $this->pct($b['locked']));
        }
        $top = collect($a['casts'])->take(3)->map(fn ($n, $s) => "{$s} (".$this->pct($n / $a['total']).')')->implode(', ');
        if ($top !== '') {
            $out[] = sprintf('In that time your casts were mostly %s. Dispels were %s of your casts then, %s of theirs.', $top, $this->pct($a['dispelShare']), $this->pct($b['dispelShare']));
        }

        return $out;
    }

    /** Where the time pressing nothing fell: their goes, yours, or neither. */
    private function idleWhy(Collection $mine, Collection $others): array
    {
        $split = function (Collection $rs) {
            $sum = ['theirs' => 0.0, 'ours' => 0.0, 'none' => 0.0];
            foreach ($rs as $r) {
                foreach ($r['v16']['idle'] as $k => $v) {
                    $sum[$k] += $v;
                }
            }
            $mins = max(0.1, $rs->sum('mins'));

            return array_map(fn ($v) => $v / $mins, $sum);
        };
        $a = $split($mine);
        $b = $split($others);
        $label = ['theirs' => 'in their goes', 'ours' => 'in your team\'s goes', 'none' => 'outside any go'];
        $worst = collect($a)->map(fn ($v, $k) => $v - $b[$k])->sortDesc()->keys()->first();

        return [
            sprintf('Seconds a minute pressing nothing: %s in their goes, %s in your team\'s goes, %s outside any go. Theirs: %s, %s, %s.',
                $this->num($a['theirs'], 1, 's'), $this->num($a['ours'], 1, 's'), $this->num($a['none'], 1, 's'),
                $this->num($b['theirs'], 1, 's'), $this->num($b['ours'], 1, 's'), $this->num($b['none'], 1, 's')),
            sprintf('Most of the difference is %s.', $label[$worst]),
        ];
    }

    /** The team's goes without your control on their healer, and how many you began locked out. */
    private function ccWhy(Collection $mine, Collection $others): array
    {
        $missed = $mine->sum(fn ($r) => $r['v16']['ccMissed']);
        if ($missed === 0) {
            return [];
        }
        $locked = $mine->sum(fn ($r) => $r['v16']['ccMissedLocked']);
        $theirMissed = $others->filter(fn ($r) => $r['v16'] !== null)->sum(fn ($r) => $r['v16']['ccMissed']);
        $theirLocked = $others->filter(fn ($r) => $r['v16'] !== null)->sum(fn ($r) => $r['v16']['ccMissedLocked']);

        return [sprintf('Of your team\'s %d goes without your control on their healer, you were locked out as %d of them started (%s)%s. The rest you began free to act.',
            $missed, $locked, $this->pct($locked / $missed),
            $theirMissed > 0 ? '; for them '.$this->pct($theirLocked / $theirMissed) : '')];
    }

    /**
     * Your rotation against the players of your spec (2026-10-17): presses per ability per minute free
     * to act, against the others in your games and the middle player of the spec in every measured
     * game (the norms). A row is marked where yours is a third or more apart on enough games. For a
     * healer, also what was pressed in the first seconds of the other team's goes (version 16).
     */
    private function rotation(string $spec, Collection $mine, Collection $others): ?array
    {
        $v10 = fn (Collection $rs) => $rs->where('v10', true);
        if ($v10($mine)->isEmpty()) {
            return null;
        }
        $perFree = function (Collection $rs) {
            $free = max(1, $rs->sum('free')) / 60;
            $presses = [];
            foreach ($rs as $r) {
                foreach ($r['presses'] as $s => $n) {
                    $presses[$s] = ($presses[$s] ?? 0) + $n;
                }
            }

            return collect($presses)->map(fn ($n) => $n / $free);
        };
        $a = $perFree($v10($mine));
        $b = $perFree($v10($others));
        $norms = collect(json_decode((string) @file_get_contents(\App\Console\Commands\BuildPopulation::normsPath()), true)['specs'] ?? [])
            ->firstWhere('label', $spec)['abilities'] ?? [];
        $enough = $v10($mine)->count() >= self::LEAD_BELOW && $v10($others)->count() >= self::LEAD_BELOW;

        $names = $b->sortDesc()->take(12)->keys()->merge($a->sortDesc()->take(8)->keys())->unique()
            ->reject(fn ($s) => str_starts_with($s, 'pet: '));
        $rows = $names->map(function ($s) use ($a, $b, $norms, $enough) {
            $x = $a[$s] ?? 0.0;
            $y = $b[$s] ?? 0.0;
            $apart = $enough && max($x, $y) >= 0.2 && ($y > 0 ? abs($x - $y) / $y >= 0.35 : $x > 0);

            return [
                'spell' => $s,
                'you' => $this->num($x, 1, ''),
                'others' => $this->num($y, 1, ''),
                'norm' => isset($norms[$s]['p50']) ? $this->num((float) $norms[$s]['p50'], 1, '') : null,
                'mark' => $apart ? ($x > $y ? 'more' : 'less') : null,
                'sort' => $y,
            ];
        })->sortByDesc('sort')->values()->all();
        $icons = $this->icons->for(array_column($rows, 'spell'));
        foreach ($rows as &$r) {
            $r['icon'] = ($icons[$r['spell']] ?? null)?->icon_name ? \App\Support\DesktopAsset::icon($icons[$r['spell']]->icon_name) : null;
            unset($r['sort']);
        }
        unset($r);

        // A healer's first casts in their goes: share of the opening casts, yours against theirs.
        $opening = null;
        $v16 = fn (Collection $rs) => $rs->filter(fn ($r) => $r['v16'] !== null && $r['v16']['openingGoes'] > 0);
        if ($mine->first()['healer'] && $v16($mine)->isNotEmpty()) {
            $share = function (Collection $rs) {
                $sum = [];
                foreach ($rs as $r) {
                    foreach ($r['v16']['opening'] as $s => $n) {
                        $sum[$s] = ($sum[$s] ?? 0) + $n;
                    }
                }

                return ['goes' => $rs->sum(fn ($r) => $r['v16']['openingGoes']), 'casts' => $sum];
            };
            $x = $share($v16($mine));
            $y = $share($v16($others));
            if ($x['goes'] >= 5) {
                $spells = collect($x['casts'])->merge($y['casts'])->keys()->unique()
                    ->sortByDesc(fn ($s) => ($y['casts'][$s] ?? 0) / max(1, $y['goes']) + ($x['casts'][$s] ?? 0) / max(1, $x['goes']))->take(8);
                $oIcons = $this->icons->for($spells->all());
                $opening = [
                    'goes' => $x['goes'],
                    'theirGoes' => $y['goes'],
                    'rows' => $spells->map(fn ($s) => [
                        'spell' => $s,
                        'icon' => ($oIcons[$s] ?? null)?->icon_name ? \App\Support\DesktopAsset::icon($oIcons[$s]->icon_name) : null,
                        'you' => $this->pct(($x['casts'][$s] ?? 0) / max(1, $x['goes'])),
                        'others' => $y['goes'] > 0 ? $this->pct(($y['casts'][$s] ?? 0) / $y['goes']) : null,
                    ])->values()->all(),
                ];
            }
        }

        return ['rows' => $rows, 'enough' => $enough, 'opening' => $opening];
    }

    /**
     * The one thing to work on: the habit furthest behind the other players of the spec, on
     * enough games on both sides to trust (a lead never becomes the focus). Damage and healing are
     * what a game produces, not something done in it, so a habit behind comes first; and a
     * healer's damage is never the focus, since it falls whenever there is more to heal.
     */
    private function focus(array $habits, bool $healer): ?array
    {
        $behind = collect($habits)->where('status', 'behind')
            ->reject(fn ($h) => $healer && $h['key'] === 'damage');

        return $behind->first(fn ($h) => ! in_array($h['key'], self::OUTCOMES, true)) ?? $behind->first();
    }

    /**
     * One measure, worded. $moreIsBetter decides which direction is "behind".
     */
    private function habit(string $key, string $title, bool $moreIsBetter, string $what, ?float $you, ?float $others, int $youN, int $othersN,
        int $decimals, string $unit, array $lines, ?array $trend, string $caveat, ?array $list = null): array
    {
        $fmt = fn (?float $v) => $unit === '%' ? $this->pct($v) : $this->num($v, $decimals, $unit);
        // Relative to the others; when theirs is zero, any of yours is all the way to one side.
        $gap = match (true) {
            $you === null || $others === null => null,
            $others > 0 => ($you - $others) / $others,
            default => $you > 0 ? INF : 0.0,
        };
        $status = match (true) {
            $you === null || $others === null => 'none',
            $youN < self::LEAD_BELOW || $othersN < self::LEAD_BELOW => 'lead',
            abs($gap ?? 0) < self::GAP => 'level',
            ($gap > 0) === $moreIsBetter => 'ahead',
            default => 'behind',
        };

        return [
            'key' => $key,
            'title' => $title,
            'what' => $what,
            'you' => $fmt($you),
            'others' => $fmt($others),
            'youN' => $youN,
            'othersN' => $othersN,
            'status' => $status,
            'gap' => $gap,
            'lines' => $lines,
            'trend' => $trend && $trend['n'] ? ['recent' => $fmt($trend['recent']), 'earlier' => $fmt($trend['earlier']), 'n' => $trend['n']] : null,
            'session' => $trend ? $this->session($trend, $moreIsBetter, $fmt) : null,
            'series' => $trend['series'] ?? [],
            'ref' => $others,
            'chart' => $this->spark($trend['series'] ?? [], $others),
            'caveat' => $caveat,
            'list' => $list,
        ];
    }

    /**
     * The last session set against the player's own sessions before it: their range, never their
     * average. Against the average, one Feral's 16% idle read as "better" after sessions of 24, 16
     * and 13%: an ordinary session made to look like progress. Outside the range it is the best or
     * worst session yet, which is plainly checkable; inside it, within the usual swing. The range
     * takes sessions of SESSION_MIN games or more, and needs SESSIONS_FOR_RANGE of them.
     */
    private function session(array $trend, bool $moreIsBetter, callable $fmt): ?array
    {
        $last = end($trend['series']);
        if (! $last || $last['v'] === null || $trend['beforeLast'] === null) {
            return null;
        }
        $past = collect(array_slice($trend['series'], 0, -1))->where('n', '>=', self::SESSION_MIN)->whereNotNull('v')->pluck('v');
        $range = $past->count() >= self::SESSIONS_FOR_RANGE ? [$past->min(), $past->max()] : null;
        $moved = $range && $last['n'] >= self::SESSION_MIN ? match (true) {
            $last['v'] > $range[1] => $moreIsBetter ? 'best' : 'worst',
            $last['v'] < $range[0] => $moreIsBetter ? 'worst' : 'best',
            default => 'within',
        } : null;
        $day = Carbon::parse($last['day'])->format('D j M');

        return [
            'value' => $fmt($last['v']),
            'n' => $last['n'],
            'day' => $day,
            'before' => $fmt($trend['beforeLast']),
            'range' => $range ? [$fmt($range[0]), $fmt($range[1])] : null,
            'sessions' => $past->count(),
            'moved' => $moved,
            'line' => "Last session ({$day}): ".$fmt($last['v']).($range
                ? '; your sessions before it ran '.$fmt($range[0]).' to '.$fmt($range[1]).'.'
                : ', against '.$fmt($trend['beforeLast']).' before it.'),
        ];
    }

    /**
     * A small line of one habit by session, drawn as inline SVG (the app's IE11 control draws it:
     * match-review.md): a point per session, oldest first, and the other players' level dashed.
     *
     * @param  array<int, array{day: string, v: ?float, n: int}>  $series
     * @return array{w: int, h: int, points: string, last: array{0: float, 1: float}, ref: ?float, from: string, to: string}|null
     */
    private function spark(array $series, ?float $ref, int $w = 180, int $h = 40): ?array
    {
        $pts = array_values(array_filter($series, fn ($s) => $s['v'] !== null && is_finite($s['v'])));
        if (count($pts) < 2) {
            return null;
        }
        $ref = $ref !== null && is_finite($ref) ? $ref : null;
        $all = array_merge(array_column($pts, 'v'), $ref === null ? [] : [$ref]);
        [$lo, $hi] = [min($all), max($all)];
        $pad = ($hi - $lo) * 0.15 ?: max(abs($hi) * 0.5, 0.01);
        [$lo, $hi] = [$lo - $pad, $hi + $pad];
        $x = fn (int $i) => round(4 + $i * ($w - 8) / (count($pts) - 1), 1);
        $y = fn (float $v) => round(($h - 4) - ($v - $lo) / ($hi - $lo) * ($h - 8), 1);
        $last = count($pts) - 1;

        return [
            'w' => $w,
            'h' => $h,
            'points' => implode(' ', array_map(fn ($i) => $x($i).','.$y($pts[$i]['v']), array_keys($pts))),
            'last' => [$x($last), $y($pts[$last]['v'])],
            'ref' => $ref === null ? null : $y($ref),
            'from' => Carbon::parse($pts[0]['day'])->format('j M'),
            'to' => Carbon::parse($pts[$last]['day'])->format('j M'),
        ];
    }

    /**
     * Your top abilities by share of the total, each beside the others' share of the same ability:
     * the mix, not the amount, since the amount depends on the comp. "Other" (the rest of a round
     * past its top abilities) is left out.
     */
    private function mixRows(Collection $mine, Collection $others, string $key, string $label): ?array
    {
        $share = function (Collection $rs) use ($key) {
            $by = [];
            foreach ($rs as $r) {
                foreach ($r[$key] as $spell => $amount) {
                    $by[$spell] = ($by[$spell] ?? 0) + $amount;
                }
            }
            unset($by['Other']);
            $total = array_sum($by);

            return $total > 0 ? array_map(fn ($v) => $v / $total, $by) : [];
        };
        $you = $share($mine);
        $them = $share($others);
        arsort($you);

        $rows = [];
        foreach (array_slice($you, 0, 6, true) as $spell => $s) {
            $rows[$spell] = round(100 * $s).'% · others '.round(100 * ($them[$spell] ?? 0)).'%';
        }

        return $this->iconRows($rows, $label);
    }

    /** A labelled list of spells with a value each, with their icons. */
    private function iconRows(array $values, string $label): ?array
    {
        if (! $values) {
            return null;
        }
        $icons = $this->icons->for(array_keys($values));

        return ['label' => $label, 'rows' => collect($values)->map(fn ($v, $spell) => [
            'spell' => $spell,
            'value' => $v,
            'icon' => \App\Support\DesktopAsset::icon(($icons[$spell] ?? null)?->icon_name),
        ])->values()->all()];
    }

    // ------------------------------------------------------------------ formatting and intervals

    private function plural(string $spec): string
    {
        return $spec.'s';
    }

    private function pct(?float $v): string
    {
        return $v === null ? '-' : round(100 * $v).'%';
    }

    private function pctOf(int $n, int $of): string
    {
        return $of > 0 ? sprintf('%d%% (%d of %d)', round(100 * $n / $of), $n, $of) : 'no goes';
    }

    private function num(?float $v, int $decimals, string $unit): string
    {
        return $v === null ? '-' : number_format($v, $decimals).$unit;
    }

    private function secs(?float $s): string
    {
        return $s === null ? '-' : round($s).'s';
    }

    private function union(array $iv): array
    {
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($iv as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    private function len(array $iv): float
    {
        return array_sum(array_map(fn ($x) => $x[1] - $x[0], $iv));
    }
}
