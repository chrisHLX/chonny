<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One game, read for the desktop app (tools/log-manager): who you played and how experienced they
 * are, how each death happened, both sides' goes, defensives, interrupts and time crowd-controlled,
 * for a loss the same "worth a look" rules the site's loss split uses, the checks and the damage
 * and healing breakdown (RoundAnalysisService version 5), and your own notes: per round from the
 * game panel, and per game from the Matches page (on that game, or on the next one played).
 *
 * Built only from what RoundAnalysisService stored (`arena_rounds.payload['analysis']`), the
 * experience PlayerExperienceService cached and the app's notes file, so a whole history renders in
 * a couple of seconds and nothing here reads a raw log or calls Blizzard. `wow:sync` does both.
 *
 * Rendered as a standalone HTML page (resources/views/desktop/game-card.blade.php) that the app
 * shows in Windows' built-in browser control, which is IE11: the view uses no CSS variables, grid
 * or flex gaps. Icons are local files (file:// URLs into storage/app/public), because the app has
 * no web server behind it.
 *
 * Experience is cached for 7 days. The command keeps what an earlier run saw for a player whose
 * cache has since lapsed, so an old game's card does not lose its opponents' experience.
 */
class GameCardService
{
    /** A note this long after a round ends still belongs to it: the scoreboard, the queue. */
    private const NOTE_AFTER = 300;

    public function __construct(
        private PlayerExperienceService $experience,
        private MatchAnalysisService $analysis,
        private SpellIconIndex $icons,
    ) {}

    /**
     * Each game carries a signature (`sig`) of everything its card is drawn from: its rounds as
     * stored, the notes that land on it, its players' experience and the card code itself. A game
     * whose signature is in $known is not drawn again and comes back with `html` null; the caller
     * keeps the card it already has. Before this every sync and every note redrew every card (4s
     * for 146 games on 2026-10-02, and growing with each game played).
     *
     * @param  array<string, array>  $remembered  experience by full name from an earlier run
     * @param  array<int, array>  $notes  the app's notes (tools/log-manager), each with `at` and `text`
     * @param  array<string, string>  $known  lobby id => the signature of the card already drawn
     * @return array{games: array<string, array>, experience: array<string, array>}
     */
    public function build(User $user, array $remembered = [], array $notes = [], array $known = []): array
    {
        $this->payloads = [];

        $lobbies = ArenaRound::query()
            ->where('user_id', $user->id)
            ->orderBy('played_at')
            ->get()
            ->filter(fn (ArenaRound $r) => isset($this->payload($r)['analysis']))
            ->groupBy('lobby_id');

        $names = $lobbies->flatten(1)->flatMap(fn (ArenaRound $r) => array_column($this->payload($r)['analysis']['players'], 'name'))->unique()->values()->all();
        $xp = [];
        foreach ($this->experience->cachedMany($names) as $name => $hit) {
            $hit ??= $remembered[$name] ?? null;
            if ($hit !== null) {
                $xp[$name] = $hit;
            }
        }

        [$notesByRound, $notesByGame] = $this->attachNotes($lobbies, $notes);

        $code = $this->codeFingerprint();
        $games = [];
        foreach ($lobbies as $lobby => $rounds) {
            // By time: `sequence` is 1 on every round wow:sync stores, so it cannot order a lobby.
            $rounds = $rounds->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values();
            $roundNotes = $rounds->mapWithKeys(fn (ArenaRound $r) => [$r->id => $notesByRound[$r->id] ?? []])->all();
            $names = $rounds->flatMap(fn (ArenaRound $r) => array_column($this->payload($r)['analysis']['players'], 'name'))->unique()->sort()->values();
            $sig = md5(json_encode([
                $code,
                $rounds->map(fn (ArenaRound $r) => [$r->id, (string) $r->updated_at])->all(),
                $roundNotes,
                $notesByGame[$lobby] ?? [],
                $names->mapWithKeys(fn ($n) => [$n => $xp[$n] ?? null])->all(),
            ]));
            $entry = [
                'playedAt' => (string) $rounds->first()->played_at,
                'bracket' => $rounds->first()->bracket,
                'notes' => $rounds->sum(fn (ArenaRound $r) => count($notesByRound[$r->id] ?? [])) + count($notesByGame[$lobby] ?? []),
                'sig' => $sig,
            ];

            if (($known[$lobby] ?? null) === $sig) {
                $games[$lobby] = $entry + ['html' => null];

                continue;
            }

            $shuffle = $rounds->count() > 1 || str_contains($rounds->first()->bracket, 'Shuffle');
            $vm = $shuffle ? $this->shuffleModel($rounds, $xp, $notesByRound) : $this->gameModel($rounds->first(), $xp, $notesByRound);
            $vm['gameNotes'] = $this->gameNoteRows($notesByGame[$lobby] ?? []);

            $games[$lobby] = $entry + ['html' => view('desktop.game-card', ['g' => $vm])->render()];
        }

        return ['games' => $games, 'experience' => $xp];
    }

    /** @var array<int, array> round id => its decoded payload, for one build */
    private array $payloads = [];

    /**
     * A round's payload, decoded once. Eloquent's `array` cast decodes the JSON again on every read,
     * and a card reads it many times: with 14 MB stored over 212 rounds that was about a second of
     * every build, even one that drew nothing (2026-10-02).
     */
    private function payload(ArenaRound $r): array
    {
        // Only the two parts a card reads; combatants and moments are half the bulk and unused here.
        return $this->payloads[$r->id] ??= array_intersect_key($r->payload ?? [], ['analysis' => true, 'metadata' => true]);
    }

    /**
     * Changes when the card's own code does: this class and every template under desktop/. A
     * change to the spell data behind an icon does not move it; a full redraw is `--fresh`.
     */
    private function codeFingerprint(): string
    {
        $files = array_merge([__FILE__], glob(resource_path('views/desktop/*.blade.php')), glob(resource_path('views/desktop/partials/*.blade.php')));
        sort($files);

        return md5(implode('|', array_map(fn ($f) => $f.':'.filemtime($f), $files)));
    }

    // ------------------------------------------------------------------ one game (2v2, 3v3)

    private function gameModel(ArenaRound $round, array $xp, array $notes): array
    {
        $a = $this->payload($round)['analysis'];
        $players = $this->players($a['players'], $xp, $a['lockout'] ?? []);
        $goes = collect($a['goes']);
        $kicks = collect($a['kicks']);
        $side = fn (string $s) => $goes->where('side', $s);
        $glad = fn (string $s) => $players->where('side', $s)->sum(fn ($p) => $p['xp']['glad'] ?? 0);

        return [
            'kind' => 'game',
            'bracket' => $round->bracket,
            'won' => $a['won'],
            'playedAt' => $round->played_at?->format('D j M, H:i'),
            'duration' => $this->clock((float) ($this->payload($round)['metadata']['durationInSeconds'] ?? 0)),
            'mmr' => $a['mmr'],
            'teams' => ['them' => $players->where('side', 'them')->values()->all(), 'us' => $players->where('side', 'us')->values()->all()],
            'glad' => ['them' => $glad('them'), 'us' => $glad('us')],
            'missingXp' => $players->where('xp.state', '!=', 'ok')->count(),
            'deaths' => array_map(fn ($d) => $this->death($d, $players), $a['deaths']),
            // [label, yours, theirs, whether more is better for the side that has it]
            'stats' => [
                ['Goes (bursts)', $side('us')->count(), $side('them')->count(), true],
                ['Tight goes (each link within 5s)', $side('us')->where('good', true)->count(), $side('them')->where('good', true)->count(), true],
                ['Goes that led to a kill', $side('us')->where('killLater', true)->count(), $side('them')->where('killLater', true)->count(), true],
                ['Defensives before the first death', $a['defensives']['us']['spent'], $a['defensives']['them']['spent'], null],
                ["Of those, outside the other side's goes", $a['defensives']['us']['outsideTheirGoes'], $a['defensives']['them']['outsideTheirGoes'], false],
                ['Interrupts', $kicks->where('side', 'us')->count(), $kicks->where('side', 'them')->count(), true],
                ['Interrupts on the healer', $kicks->where('side', 'us')->where('onHealer', true)->count(), $kicks->where('side', 'them')->where('onHealer', true)->count(), true],
            ],
            'look' => $a['won'] ? null : $this->look($this->analysis->lossItems($a, $xp), $players),
            'breakdown' => $this->breakdownModel(collect([$round]), $players),
            'checks' => $this->checkRows($a['checks'] ?? null, $players),
            'notes' => $this->noteRows($notes[$round->id] ?? [], $round),
        ];
    }

    // ------------------------------------------------------------------ a Solo Shuffle lobby

    private function shuffleModel(Collection $rounds, array $xp, array $notes): array
    {
        $first = $rounds->first();
        $won = $rounds->filter(fn (ArenaRound $r) => $this->payload($r)['analysis']['won'])->count();

        // Everyone in the lobby once; sides are re-dealt each round, so the roster has no teams.
        $all = $rounds->flatMap(fn (ArenaRound $r) => $this->payload($r)['analysis']['players'])->unique('guid')->values()->all();
        $roster = $this->players($all, $xp, [])->sortByDesc('you')->values();

        $rows = [];
        foreach ($rounds as $i => $round) {
            $a = $this->payload($round)['analysis'];
            $ps = $this->players($a['players'], $xp, $a['lockout'] ?? []);
            $you = $ps->firstWhere('you', true);

            // Your own buttons and the team's timing: a shuffle's other players change every round,
            // so their mistakes say little about how you play.
            $look = null;
            if (! $a['won']) {
                $mine = array_filter($this->analysis->lossItems($a, $xp), fn ($it) => $it['owner'] === ($you['spec'] ?? null) || $it['owner'] === 'Your team (burst timing)');
                $look = $this->look(array_values($mine), $ps);
            }

            $rows[] = [
                'n' => $i + 1,
                'won' => $a['won'],
                'duration' => $this->clock((float) ($this->payload($round)['metadata']['durationInSeconds'] ?? 0)),
                'with' => $ps->where('side', 'us')->where('you', false)->values()->all(),
                'against' => $ps->where('side', 'them')->values()->all(),
                'death' => isset($a['deaths'][0]) ? $this->death($a['deaths'][0], $ps) : null,
                'look' => $look,
                // Your own only, like $look: the other players change every round.
                'checks' => $this->checkRows(isset($a['checks']) ? array_values(array_filter($a['checks'], fn ($c) => $c['who'] === ($you['guid'] ?? null))) : null, $ps),
                'notes' => $this->noteRows($notes[$round->id] ?? [], $round),
            ];
        }

        return [
            'kind' => 'shuffle',
            'bracket' => $first->bracket,
            'won' => $won > $rounds->count() - $won,
            'record' => [$won, $rounds->count() - $won],
            'playedAt' => $first->played_at?->format('D j M, H:i'),
            'roster' => $roster->all(),
            'rounds' => $rows,
            // The same six players all lobby, so their output adds up across the rounds.
            'breakdown' => $this->breakdownModel($rounds, $roster, teams: false),
        ];
    }

    // ------------------------------------------------------------------ damage, healing, checks

    /**
     * Each player's damage onto enemy players, healing and absorbs onto their own team, by ability,
     * summed over the rounds given (RoundAnalysisService `breakdown`, version 5). Null when none of
     * the rounds was analysed with it, so an older game says how to get it rather than showing zeros.
     */
    private function breakdownModel(Collection $rounds, Collection $players, bool $teams = true): ?array
    {
        $with = $rounds->filter(fn (ArenaRound $r) => isset($this->payload($r)['analysis']['breakdown']));
        if ($with->isEmpty()) {
            return null;
        }

        $sum = [];
        foreach ($with as $r) {
            foreach ($this->payload($r)['analysis']['breakdown'] as $guid => $b) {
                $sum[$guid]['alive'] = ($sum[$guid]['alive'] ?? 0) + $b['alive'];
                $sum[$guid]['idle'] = ($sum[$guid]['idle'] ?? 0) + $b['idle'];
                foreach (['damage', 'healing', 'absorbs'] as $kind) {
                    foreach ($b[$kind] as $row) {
                        $cur = $sum[$guid][$kind][$row['spell']] ?? ['amount' => 0, 'hits' => 0, 'over' => 0];
                        $sum[$guid][$kind][$row['spell']] = ['amount' => $cur['amount'] + $row['amount'], 'hits' => $cur['hits'] + $row['hits'], 'over' => $cur['over'] + $row['over']];
                    }
                }
            }
        }

        $names = [];
        foreach ($sum as $b) {
            foreach (['damage', 'healing', 'absorbs'] as $kind) {
                $names = array_merge($names, array_map(fn ($s) => preg_replace('/^pet: /', '', $s), array_keys($b[$kind] ?? [])));
            }
        }
        $icons = $this->icons->for(array_values(array_unique($names)));

        // Your team first (you at the top), then theirs. A shuffle lobby has no fixed sides: you, then
        // everyone else.
        $ordered = $players->sortBy(fn ($p) => [$teams && $p['side'] !== 'us' ? 1 : 0, $p['you'] ? 0 : 1, $p['healer'] ? 1 : 0])->values();

        $rows = [];
        foreach ($ordered as $p) {
            $b = $sum[$p['guid']] ?? null;
            if ($b === null) {
                continue;
            }
            $kinds = [];
            foreach (['damage' => 'Damage', 'healing' => 'Healing', 'absorbs' => 'Absorbs'] as $kind => $label) {
                // "Other" (everything past a round's top abilities) always last.
                $list = collect($b[$kind] ?? [])->map(fn ($v, $spell) => ['spell' => $spell] + $v)
                    ->sortBy(fn ($x) => [$x['spell'] === 'Other' ? 1 : 0, -$x['amount']])->values();
                $total = $list->sum('amount');
                if ($total <= 0) {
                    continue;
                }
                $kinds[] = [
                    'label' => $label,
                    'total' => $this->amount($total),
                    'perSecond' => $this->amount($total / max(1, $b['alive'])),
                    'rows' => $list->map(fn ($x) => [
                        'spell' => $x['spell'],
                        'icon' => $this->spellIcon($icons, preg_replace('/^pet: /', '', $x['spell'])),
                        'amount' => $this->amount($x['amount']),
                        'share' => round(100 * $x['amount'] / $total),
                        'hits' => $x['hits'],
                        'overheal' => $kind === 'healing' && $x['amount'] + $x['over'] > 0 ? round(100 * $x['over'] / ($x['amount'] + $x['over'])) : null,
                    ])->all(),
                ];
            }
            $totals = collect($kinds)->keyBy('label');
            $rows[] = [
                'id' => 'b'.substr(md5($p['guid']), 0, 8),
                'name' => $p['name'], 'spec' => $p['spec'], 'color' => $p['color'], 'icon' => $p['icon'],
                'you' => $p['you'], 'them' => $teams && $p['side'] === 'them',
                'damage' => $totals['Damage']['total'] ?? '0',
                'healing' => $totals['Healing']['total'] ?? '0',
                'absorbs' => $totals['Absorbs']['total'] ?? '0',
                'idle' => round(100 * $b['idle'] / max(1, $b['alive'])),
                'kinds' => $kinds,
            ];
        }

        return ['players' => $rows, 'partial' => $with->count() < $rounds->count()];
    }

    /**
     * RoundAnalysisService `checks`, worded. Null when the round was analysed before checks existed.
     *
     * @param  array<int, array>|null  $checks
     */
    private function checkRows(?array $checks, Collection $players): ?array
    {
        if ($checks === null) {
            return null;
        }
        $byGuid = $players->keyBy('guid');
        $icons = $this->icons->for(array_column($checks, 'spell'));

        return array_map(function ($c) use ($byGuid, $icons) {
            $who = $byGuid[$c['who']] ?? null;
            $text = match ($c['kind']) {
                'cooldown-ready' => sprintf('sat ready for %s in all: at least %d whole use%s lost (pressed %d time%s, %ds cooldown)',
                    $this->clock($c['ready']), $c['lost'], $c['lost'] === 1 ? '' : 's', $c['presses'], $c['presses'] === 1 ? '' : 's', $c['cooldown']),
                'on-immune' => sprintf('on %s at %s, who was already in %s: it removed nothing',
                    $byGuid[$c['on']]['name'] ?? '?', $this->clock($c['t']), $c['immunity']),
                default => '',
            };

            return [
                'name' => $who['name'] ?? '?', 'color' => $who['color'] ?? '#8A8A9A', 'you' => $who['you'] ?? false,
                'spell' => $c['spell'], 'icon' => $this->spellIcon($icons, $c['spell']), 'text' => $text,
            ];
        }, $checks);
    }

    private function amount(float $n): string
    {
        return match (true) {
            $n >= 1_000_000 => number_format($n / 1_000_000, 2).'M',
            $n >= 1_000 => number_format($n / 1_000).'k',
            default => (string) (int) round($n),
        };
    }

    // ------------------------------------------------------------------ pieces

    /** @return Collection<int, array> */
    private function players(array $players, array $xp, array $lockout): Collection
    {
        $specs = Specialization::query()->whereIn('external_spec_id', array_column($players, 'specExternalId'))->get()->keyBy('external_spec_id');
        $colors = config('wow_classes.colors');

        return collect($players)->map(function ($p) use ($specs, $colors, $xp, $lockout) {
            $x = $xp[$p['name']] ?? null;
            $spec = $specs[$p['specExternalId'] ?? 0] ?? null;

            return [
                'guid' => $p['guid'],
                'name' => $this->short($p['name']),
                'full' => $p['name'],
                'spec' => $p['spec'],
                'side' => $p['side'],
                'healer' => $p['healer'],
                'you' => $p['logger'],
                'color' => $colors[$p['classSlug'] ?? ''] ?? '#8A8A9A',
                'icon' => $spec?->icon_name ? $this->fileUrl('spec-icons/'.$spec->icon_name) : null,
                'lockout' => $lockout[$p['guid']] ?? null,
                'xp' => match (true) {
                    $x === null => ['state' => 'pending'],
                    ! ($x['found'] ?? false) => ['state' => 'none'],
                    default => ['state' => 'ok', 'glad' => $x['gladSeasons'] ?? 0, 'exp' => $x['exp3v3'] ?? null, 'title' => $x['bestRank'] ?? null],
                },
            ];
        });
    }

    private function death(array $d, Collection $players): array
    {
        $p = $players->firstWhere('guid', $d['who']);
        $byName = $players->keyBy('full');
        $h = $d['healer'];
        $hasHealer = $players->where('side', $d['side'])->contains('healer', true);

        $healer = null;
        if ($hasHealer && $h['state'] !== 'was the kill') {
            $healer = match ($h['state']) {
                'locked' => ['label' => 'locked out at the death', 'tone' => 'bad'],
                'ended' => ['label' => 'lockout ended '.$h['endedAgo'].'s before', 'tone' => $h['endedAgo'] <= 1 ? 'bad' : 'warn'],
                default => ['label' => 'free', 'tone' => 'good'],
            };
            $healer['whose'] = $d['side'] === 'us' ? 'Your healer' : 'Their healer';
            $healer['medallion'] = $h['medallionUsedAt'] ? 'Medallion used at '.collect($h['medallionUsedAt'])->map(fn ($t) => $this->clock($t))->implode(', ') : 'Medallion not used';
        }

        $spells = array_filter(array_merge([$d['killingBlow']['spell'] ?? null], array_column($d['defensives30s'], 'spell')));
        $icons = $this->icons->for($spells);

        return [
            'clock' => $this->clock($d['t']),
            'name' => $p['name'] ?? '?',
            'spec' => $p['spec'] ?? '?',
            'color' => $p['color'] ?? '#8A8A9A',
            'icon' => $p['icon'] ?? null,
            'ours' => $d['side'] === 'us',
            'killingBlow' => $d['killingBlow'] ? ['spell' => $d['killingBlow']['spell'], 'icon' => $this->spellIcon($icons, $d['killingBlow']['spell'])] : null,
            'shares' => collect($d['shares'])->map(fn ($pct, $name) => [
                'name' => isset($byName[$name]) ? $byName[$name]['name'] : $this->short($name),
                'color' => $byName[$name]['color'] ?? '#52525F',
                'pct' => $pct,
            ])->values()->all(),
            'healer' => $healer,
            'goStartedAgo' => $d['goStartedAgo'],
            'goWhose' => $d['side'] === 'us' ? 'Their' : 'Your',
            'defensives' => array_map(fn ($x) => [
                'spell' => $x['spell'], 'icon' => $this->spellIcon($icons, $x['spell']),
                'who' => $this->short($x['who']), 'ago' => $x['ago'],
            ], $d['defensives30s']),
        ];
    }

    /** One row per distinct item, with a count where a rule fired more than once (once per go). */
    private function look(array $items, Collection $players): array
    {
        $icons = $this->icons->for(array_filter(array_column($items, 'spell')));
        // Items are owned by your side's specs; an enemy of the same spec must not lend its colour.
        $bySpec = $players->where('side', 'us')->keyBy('spec');

        return collect($items)
            ->groupBy(fn ($i) => $i['owner'].'|'.$i['text'])
            ->map(function (Collection $g) use ($icons, $bySpec) {
                $i = $g->first();
                $them = str_starts_with($i['owner'], 'Them');

                return [
                    'owner' => $i['owner'],
                    'color' => $bySpec[$i['owner']]['color'] ?? ($them ? '#8A8A9A' : '#C8952C'),
                    'text' => $i['text'],
                    'icon' => $i['spell'] ? $this->spellIcon($icons, $i['spell']) : null,
                    'count' => $g->count(),
                    'them' => $them,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Each note goes to the round it was written for. The app stamps a note with the round's start
     * (`roundStart`, the ARENA_MATCH_START time) when it knows it; otherwise the note's own time
     * decides: inside a round, or up to NOTE_AFTER seconds after it ends.
     *
     * Both clocks are WoW's local wall clock: the log writes it, `played_at` stores it as written,
     * and the app stamps notes with the same machine's clock.
     *
     * Two kinds of note go on a whole game instead (the second array):
     * - `game` set: written in the app against that game (its lobby id, the app's match key).
     * - `kind` "next": written for the next game, so it goes on the first game that starts after it
     *   was written. Until one is played it is on no card; the app lists it as waiting.
     *
     * @param  Collection<string, Collection<int, ArenaRound>>  $lobbies
     * @return array{0: array<int, array<int, array>>, 1: array<string, array<int, array>>} round id => notes, lobby id => notes
     */
    private function attachNotes(Collection $lobbies, array $notes): array
    {
        $byGame = [];
        $starts = $lobbies->map(fn (Collection $rs) => $rs->min(fn (ArenaRound $r) => $r->played_at?->getTimestamp()))->filter()->sort();
        $notes = array_values(array_filter($notes, function ($note) use ($lobbies, $starts, &$byGame) {
            if (trim((string) ($note['text'] ?? '')) === '' || ! isset($note['at'])) {
                return true;
            }
            $at = Carbon::parse($note['at'], 'UTC')->getTimestamp();
            if (isset($note['game'])) {
                if ($lobbies->has($note['game'])) {
                    $byGame[$note['game']][] = $note + ['_at' => $at];
                }

                return false;
            }
            if (($note['kind'] ?? 'note') === 'next') {
                $next = $starts->filter(fn ($start) => $start >= $at)->keys()->first();
                if ($next !== null) {
                    $byGame[$next][] = $note + ['_at' => $at];
                }

                return false;
            }

            return true;
        }));

        $rounds = $lobbies->flatten(1);
        $out = [];
        $spans = $rounds->filter(fn (ArenaRound $r) => $r->played_at !== null)->map(fn (ArenaRound $r) => [
            'id' => $r->id,
            'from' => $r->played_at->getTimestamp(),
            'to' => $r->played_at->getTimestamp() + (int) ($this->payload($r)['metadata']['durationInSeconds'] ?? 0),
        ])->sortBy('from')->values();

        foreach ($notes as $note) {
            if (! isset($note['at']) || trim((string) ($note['text'] ?? '')) === '' && ($note['kind'] ?? 'note') !== 'mark') {
                continue;
            }
            $at = Carbon::parse($note['at'], 'UTC')->getTimestamp();
            $start = isset($note['roundStart']) ? Carbon::parse($note['roundStart'], 'UTC')->getTimestamp() : null;

            $hit = $start !== null
                ? $spans->first(fn ($s) => abs($s['from'] - $start) <= 2)
                : null;
            $hit ??= $spans->first(fn ($s) => $at >= $s['from'] - 2 && $at <= $s['to'] + 2);
            $hit ??= $spans->filter(fn ($s) => $at > $s['to'] && $at <= $s['to'] + self::NOTE_AFTER)->last();

            if ($hit) {
                $out[$hit['id']][] = $note + ['_at' => $at];
            }
        }

        return [$out, $byGame];
    }

    /** Notes on a whole game: written after it in the app, or before it for "the next game". */
    private function gameNoteRows(array $notes): array
    {
        return collect($notes)->sortBy('_at')->map(fn ($n) => [
            'when' => Carbon::createFromTimestamp($n['_at'], 'UTC')->format('D j M, H:i'),
            'text' => trim((string) $n['text']),
            'next' => ($n['kind'] ?? 'note') === 'next',
        ])->values()->all();
    }

    private function noteRows(array $notes, ArenaRound $round): array
    {
        $start = $round->played_at?->getTimestamp() ?? 0;

        return collect($notes)->sortBy('_at')->map(fn ($n) => [
            'clock' => $n['_at'] >= $start ? $this->clock($n['_at'] - $start) : 'before',
            'text' => trim((string) ($n['text'] ?? '')),
            'mark' => ($n['kind'] ?? 'note') === 'mark',
        ])->values()->all();
    }

    private function spellIcon(array $icons, string $name): ?string
    {
        $spell = $icons[$name] ?? null;

        return $spell?->icon_name ? $this->fileUrl('spell-icons/'.$spell->icon_name) : null;
    }

    private function fileUrl(string $relative): string
    {
        return 'file:///'.str_replace('\\', '/', storage_path('app/public/'.$relative));
    }

    private function clock(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }

    private function short(string $name): string
    {
        return explode('-', $name)[0];
    }
}
