<?php

namespace App\Console\Commands;

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellEffect;
use App\Support\ForeverTooltip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * WoW Forever's class abilities, sorted the way retail sorts its spells, baked into the committed
 * data/forever/abilities.json that the Forever PvP Guides page reads (docs/forever/pvp-guides.md).
 *
 * The source is the game's own tables (wago.tools, product `wowf`), the same export the Auction
 * House addon is built from. Nothing here is researched or typed in by hand: what a class has is
 * read from its three tree skill lines and its talents, and the category comes from retail's own
 * categoriser (ModuleSpellReferenceService::categorizeFromOwnEffects / categorizeFromEffects), fed
 * the same inputs it gets in retail:
 *
 * - Effect types under retail's names. The numbers are named from retail's raw dumps
 *   (data/spelldata/raw/*.txt print "Apply Aura (6) | Modify Damage Taken% (87)"), checked
 *   2026-11-23 on the 346 spell ids both games have: 249 share an effect exactly, and the rest are
 *   spells retail reworked since (Maul is weapon damage in Forever, school damage in retail).
 * - The mechanic, from Forever's SpellCategories (MECHANIC below says how each number was named).
 * - Retail's hand-curated columns (dr_category, is_interrupt, is_mobility), carried over only where
 *   a Forever rank has the SAME spell id as a retail spell, never by name: retail's Blast Wave is a
 *   curated knockback, Forever's is a different spell under the same name.
 *
 * Tooltips are resolved by ForeverTooltip; one with anything it cannot work out ships with no
 * description rather than a raw token.
 */
class BuildForeverAbilities extends Command
{
    protected $signature = 'forever:abilities
        {dir : A folder of wago.tools wowf DB2 CSVs}
        {--download : Fetch the tables from wago.tools into the folder first}
        {--icons : Fetch any icon not yet in storage/app/public/forever-icons from Blizzard}
        {--listfile= : wowdev community-listfile.csv, for the icons Blizzard serves only by name}';

    protected $description = "WoW Forever's class abilities by retail's categories, into data/forever/abilities.json";

    private const TABLES = [
        'ChrClasses', 'SkillLine', 'SkillLineAbility', 'SkillRaceClassInfo', 'Spell', 'SpellName', 'SpellMisc',
        'SpellEffect', 'SpellCooldowns', 'SpellCategories', 'SpellLevels', 'SpellDuration', 'SpellCastTimes',
        'SpellRange', 'SpellPower', 'SpellRadius', 'SpellAuraOptions', 'SpellTargetRestrictions', 'Talent', 'TalentTab',
    ];

    /** SkillRaceClassInfo.Flags on exactly the 27 class tree lines (Arms, Fury, Protection...), measured 2026-11-23. */
    private const TREE_LINE_FLAGS = '1040';

    /**
     * SpellCategories.Mechanic -> retail's mechanic name. Paired 2026-11-23 through spells both games
     * hold under one id (retail's dump names the mechanic, Forever's table numbers it): 2, 4, 5, 7, 9,
     * 10, 11, 12, 13, 14, 15, 18, 19, 20, 23, 25, 26, 30. The rest by the Forever spells carrying them:
     * 1 (Mind Control, Seduction), 3 (Disarm), 17 (Polymorph, Hex), 24 (Crippling Dread), 31 (Battle Cry).
     */
    private const MECHANIC = [
        1 => 'Charm', 2 => 'Disorient', 3 => 'Disarm', 4 => 'Distract', 5 => 'Flee', 7 => 'Root',
        9 => 'Silence', 10 => 'Sleep', 11 => 'Snare', 12 => 'Stun', 13 => 'Freeze', 14 => 'Incapacitate',
        15 => 'Bleed', 17 => 'Polymorph', 18 => 'Banish', 19 => 'Shield', 20 => 'Shackle', 23 => 'Turn',
        24 => 'Horrify', 25 => 'Invulnerable', 26 => 'Interrupt', 30 => 'Sap', 31 => 'Enrage',
    ];

    /**
     * Effect numbers on Forever's abilities that no retail spell carries, so retail's dumps never name
     * them. Named by the Forever spells that carry them: 58 is on Auto Shot, Raptor Strike and Primal
     * Bite, the old "weapon damage plus a flat amount" effect.
     */
    private const EFFECTS_NOT_IN_RETAIL = [58 => 'Weapon Damage'];

    /** SpellPower.PowerType -> the word a tooltip uses. */
    private const POWER = [0 => 'Mana', 1 => 'Rage', 2 => 'Focus', 3 => 'Energy'];

    private string $dir;

    public function handle(ModuleSpellReferenceService $categoriser): int
    {
        $this->dir = rtrim($this->argument('dir'), '/\\');
        if ($this->option('download') && ! $this->download()) {
            return self::FAILURE;
        }
        foreach (self::TABLES as $t) {
            if (! is_file("{$this->dir}/{$t}.csv")) {
                $this->error("{$t}.csv is missing from {$this->dir} (run with --download)");

                return self::FAILURE;
            }
        }

        [$effectNames, $auraNames] = $this->retailEffectNames();
        $effectNames += self::EFFECTS_NOT_IN_RETAIL;
        $this->info(count($effectNames).' effect and '.count($auraNames).' aura numbers named from the retail dumps');

        $name = $this->column('SpellName', 'ID', 'Name_lang');
        $spell = $this->keyed('Spell', 'ID');
        $misc = $this->keyed('SpellMisc', 'SpellID');
        $levels = $this->keyed('SpellLevels', 'SpellID');
        $cooldowns = $this->keyed('SpellCooldowns', 'SpellID');
        $categories = $this->keyed('SpellCategories', 'SpellID');
        $auraOptions = $this->keyed('SpellAuraOptions', 'SpellID');
        $targets = $this->keyed('SpellTargetRestrictions', 'SpellID');
        $power = $this->keyed('SpellPower', 'SpellID');
        $durations = $this->keyed('SpellDuration', 'ID');
        $castTimes = $this->keyed('SpellCastTimes', 'ID');
        $ranges = $this->keyed('SpellRange', 'ID');
        $radii = $this->keyed('SpellRadius', 'ID');
        $skillLines = $this->keyed('SkillLine', 'ID');

        $effects = [];
        foreach ($this->rows('SpellEffect') as $r) {
            if ($r['DifficultyID'] === '0') {
                $effects[(int) $r['SpellID']][(int) $r['EffectIndex']] = $r;
            }
        }

        // The classes and their tree lines.
        $classes = [];
        foreach ($this->rows('ChrClasses') as $r) {
            $classes[(int) $r['ID']] = ['name' => $r['Name_lang'], 'slug' => strtolower($r['Filename'])];
        }
        $lineClass = [];
        foreach ($this->rows('SkillRaceClassInfo') as $r) {
            $line = $skillLines[(int) $r['SkillID']] ?? null;
            if ($r['Flags'] === self::TREE_LINE_FLAGS && $line && $line['CategoryID'] === '7') {
                $lineClass[(int) $r['SkillID']] = $this->classFromMask((int) $r['ClassMask']);
            }
        }

        // Talents: every rank's spell, and which tree. Only a one-rank talent gives a button.
        $talentTabs = $this->keyed('TalentTab', 'ID');
        $talentRank = [];
        $singleRankTalents = [];
        $multiRankTalentNames = [];   // classId => name => true
        foreach ($this->rows('Talent') as $r) {
            $ranks = array_values(array_filter(array_map(fn ($i) => (int) $r["SpellRank_{$i}"], range(0, 8))));
            $tab = $talentTabs[(int) $r['TabID']] ?? null;
            foreach ($ranks as $s) {
                $talentRank[$s] = ['ranks' => count($ranks), 'tree' => $tab['Name_lang'] ?? null];
            }
            if (count($ranks) === 1 && $tab) {
                $singleRankTalents[$ranks[0]] = ['class' => $this->classFromMask((int) $tab['ClassMask']), 'tree' => $tab['Name_lang']];
            }
            if (count($ranks) > 1 && $tab) {
                // Every rank's name: Pyroclasm's first rank has none in the tables.
                foreach ($ranks as $rank) {
                    if (($name[$rank] ?? '') !== '') {
                        $multiRankTalentNames[$this->classFromMask((int) $tab['ClassMask'])][$name[$rank]] = true;
                    }
                }
            }
        }

        // What casts what: target spell => [[source spell, effect number]], counting only sources a player
        // has (a talent, or a spell on a class's tree lines): an item or an NPC also casts some rank of
        // Frostbolt or Drain Life. Effect 36 is "Learn Spell" (a trainer, "Teach Summon Imp", Bear Form
        // teaching Growl), which is not a cast.
        $onClassLines = [];
        foreach ($this->rows('SkillLineAbility') as $r) {
            if (isset($lineClass[(int) $r['SkillLine']])) {
                $onClassLines[(int) $r['Spell']] = true;
            }
        }
        $triggeredBy = [];
        foreach ($effects as $source => $list) {
            if (! isset($onClassLines[$source]) && ! isset($talentRank[$source])) {
                continue;
            }
            foreach ($list as $e) {
                if ((int) $e['EffectTriggerSpell'] > 0) {
                    $triggeredBy[(int) $e['EffectTriggerSpell']][] = [$source, (int) $e['Effect']];
                }
            }
        }
        // A talent's effect is not a button: Impact's stun, Clearcasting, Hellfire's damage ticks, the
        // Moonkin Aura. It is cast (not learned) by a talent or by a spell of another name, or it bears
        // the name of one of its class's multi-rank talents (Pyroclasm, Entrapment, Blessed Recovery).
        $isTalentEffect = function (int $s, string $n, int $class) use ($triggeredBy, $talentRank, $name, $multiRankTalentNames): bool {
            if (isset($multiRankTalentNames[$class][$n])) {
                return true;
            }
            foreach ($triggeredBy[$s] ?? [] as [$source, $effect]) {
                if ($effect !== 36 && (isset($talentRank[$source]) || ($name[$source] ?? '') !== $n)) {
                    return true;
                }
            }

            return false;
        };

        $level = fn (int $s) => (int) ($levels[$s]['BaseLevel'] ?? 0);
        $attr0 = fn (int $s) => (int) ($misc[$s]['Attributes_0'] ?? 0);

        // Candidates: each class's tree-line abilities, then its one-rank talents.
        $picked = [];   // classId => name => [spellId => ['tree' =>, 'talent' =>]]
        $proced = [];
        $dropped = ['hidden' => 0, 'unnamed' => 0, 'rune only' => 0, 'talent rank' => 0, 'talent or spell effect' => 0];
        foreach ($this->rows('SkillLineAbility') as $r) {
            $line = (int) $r['SkillLine'];
            $class = $lineClass[$line] ?? null;
            $s = (int) $r['Spell'];
            $mask = (int) $r['ClassMask'];
            if ($class === null || ($mask !== 0 && ! ($mask & (1 << ($class - 1))))) {
                continue;
            }
            $n = $name[$s] ?? '';
            $why = match (true) {
                $n === '' => 'unnamed',
                (bool) ($attr0($s) & 0x80) => 'hidden',
                // Season of Discovery runes left in the tables: no level, and none of the 242 was cast
                // in 36,456 player casts over Chriso's logs of 20-22 Nov. The ranked ones Forever kept
                // (Penance, Water Shield, Shifting Power) have a level and were cast.
                $r['AcquireMethod'] === '3' && $level($s) === 0 => 'rune only',
                isset($talentRank[$s]) && $talentRank[$s]['ranks'] > 1 => 'talent rank',
                ! isset($talentRank[$s]) && $isTalentEffect($s, $n, $class) => 'talent or spell effect',
                default => null,
            };
            if ($why !== null) {
                $dropped[$why]++;
                if ($why === 'talent or spell effect') {
                    $proced[$n] = true;
                }

                continue;
            }
            $picked[$class][$n][$s] = ['tree' => $skillLines[$line]['DisplayName_lang'], 'talent' => isset($talentRank[$s])];
        }
        // A form's own passive ("Bear Form (Passive)") is part of the form, not a button of its own.
        foreach ($picked as $class => $abilities) {
            foreach (array_keys($abilities) as $n) {
                if (str_ends_with($n, ' (Passive)') && isset($abilities[substr($n, 0, -10)])) {
                    unset($picked[$class][$n]);
                    $dropped['talent or spell effect']++;
                    $proced[$n] = true;
                }
            }
        }
        foreach ($singleRankTalents as $s => $t) {
            $n = $name[$s] ?? '';
            if ($n !== '' && $t['class'] !== null && ! ($attr0($s) & 0x80)) {
                $picked[$t['class']][$n][$s] = ['tree' => $t['tree'], 'talent' => true];
            }
        }

        // Retail's curated columns for the spell ids both games hold.
        $allIds = [];
        foreach ($picked as $abilities) {
            foreach ($abilities as $ranks) {
                $allIds = array_merge($allIds, array_keys($ranks));
            }
        }
        $retail = Spell::query()
            ->where('patch_id', Patch::where('is_current', true)->value('id'))
            ->whereIn('spell_id', array_unique($allIds))
            ->get(['spell_id', 'name', 'dr_category', 'is_interrupt', 'is_mobility'])
            ->keyBy('spell_id');

        $tooltip = new ForeverTooltip;
        $out = [];
        $stats = ['abilities' => 0, 'described' => 0, 'no description' => 0, 'by id from retail' => [], 'categories' => []];
        foreach ($picked as $classId => $abilities) {
            $list = [];
            foreach ($abilities as $n => $ranks) {
                $ids = array_keys($ranks);
                // The highest rank is the one a level 60 has: highest level, then the newest id. A copy
                // another rank casts is not the button (Shadowguard's damage bolt beside the buff), so it
                // only stands for the ability when nothing else does.
                // A button costs something, its proc copy does not (Shadowguard's buff only links to the
                // bolt's first rank, so the cost is what tells rank 7 of the bolt from the buff).
                $castByRank = fn (int $id) => collect($triggeredBy[$id] ?? [])->contains(fn ($t) => $t[1] !== 36 && in_array($t[0], $ids, true));
                $free = fn (int $id) => ! isset($power[$id]);
                usort($ids, fn ($a, $b) => [(int) $castByRank($a), (int) $free($a), $level($b), $b] <=> [(int) $castByRank($b), (int) $free($b), $level($a), $a]);
                $top = $ids[0];
                $m = $misc[$top] ?? [];
                $cat = $categories[$top] ?? [];

                $curated = ['dr_category' => null, 'is_interrupt' => false, 'is_mobility' => false];
                foreach ($ids as $id) {
                    // Same id AND same name: retail reused some ids (99 is Demoralizing Roar here,
                    // Incapacitating Roar in retail, a curated crowd control).
                    if (($row = $retail->get($id)) && $row->name === $n) {
                        $curated['dr_category'] ??= $row->dr_category;
                        $curated['is_interrupt'] = $curated['is_interrupt'] || $row->is_interrupt;
                        $curated['is_mobility'] = $curated['is_mobility'] || $row->is_mobility;
                    }
                }
                if ($curated['dr_category'] !== null || $curated['is_interrupt'] || $curated['is_mobility']) {
                    $stats['by id from retail'][] = $n;
                }
                // An interrupt is marked in the game's own data, by an Interrupt Cast (68) effect: that is
                // what retail's hand-set is_interrupt says, so Shield Bash and Earth Shock read like Kick.
                if (! $curated['is_interrupt'] && collect($effects[$top] ?? [])->contains(fn ($e) => $e['Effect'] === '68')) {
                    $curated['is_interrupt'] = true;
                }

                $toEffects = fn (int $id) => collect($effects[$id] ?? [])->map(function (array $e) use ($effectNames, $auraNames) {
                    $aura = (int) $e['EffectAura'];
                    $effect = (int) $e['Effect'];
                    $type = $aura > 0
                        ? ($auraNames[$aura] ?? "Unknown Aura ({$aura})")
                        : (isset($effectNames[$effect]) ? "{$effectNames[$effect]} ({$effect})" : "Unknown ({$effect})");
                    $coef = (float) $e['EffectBonusCoefficient'];

                    return new SpellEffect([
                        'type' => $type,
                        'base_value' => (float) $e['EffectBasePointsF'],
                        'scaled_value' => (float) $e['EffectBasePointsF'],
                        'sp_coefficient' => $coef > 0 ? $coef : null,
                    ]);
                })->values();

                $model = new Spell([
                    'spell_id' => $top,
                    'name' => $n,
                    'dr_category' => $curated['dr_category'],
                    'is_interrupt' => $curated['is_interrupt'],
                    'is_mobility' => $curated['is_mobility'],
                    'mechanic' => self::MECHANIC[(int) ($cat['Mechanic'] ?? 0)] ?? null,
                ]);
                $model->setRelation('effects', $toEffects($top));
                $category = $categoriser->categorizeFromOwnEffects($model);
                if ($category === null) {
                    // Retail's fallback: the same-named spells' effects. Retail's table holds only player
                    // spells; Forever's holds every NPC's too, and an NPC "Stealth" or "Fade" with a stun made
                    // those read as Crowd Control. So the siblings are this ability's own other ranks.
                    // Beside them, the same-named spells a rank casts: Blizzard's damage is a spell called
                    // Blizzard that the channel casts, as in retail's data.
                    $castByRanks = [];
                    foreach ($ids as $id) {
                        foreach ($effects[$id] ?? [] as $e) {
                            $t = (int) $e['EffectTriggerSpell'];
                            if ($t > 0 && $e['Effect'] !== '36' && ($name[$t] ?? '') === $n) {
                                $castByRanks[] = $t;
                            }
                        }
                    }
                    $siblings = collect(array_unique(array_merge($ids, $castByRanks)))->reject(fn ($id) => $id === $top)->flatMap($toEffects);
                    $category = $siblings->isEmpty() ? 'Other' : $categoriser->categorizeFromEffects($siblings);
                }

                $ctx = function (int $id) use (&$ctx, $effects, $misc, $durations, $radii, $ranges, $auraOptions, $targets): array {
                    $mm = $misc[$id] ?? [];

                    return [
                        'effects' => $effects[$id] ?? [],   // keyed by EffectIndex (0-based)
                        'duration_ms' => (int) ($durations[(int) ($mm['DurationIndex'] ?? 0)]['Duration'] ?? 0),
                        'radius' => fn (int $index) => isset($radii[$index]) ? (float) $radii[$index]['Radius'] : null,
                        'range' => (float) ($ranges[(int) ($mm['RangeIndex'] ?? 0)]['RangeMax_0'] ?? 0),
                        'proc_chance' => (int) ($auraOptions[$id]['ProcChance'] ?? 0),
                        'stacks' => (int) ($auraOptions[$id]['CumulativeAura'] ?? 0),
                        'charges' => (int) ($auraOptions[$id]['ProcCharges'] ?? 0),
                        'max_targets' => (int) ($targets[$id]['MaxTargets'] ?? 0),
                        'other' => fn (int $other) => isset($effects[$other]) || isset($misc[$other]) ? $ctx($other) : null,
                    ];
                };
                $description = $tooltip->resolve((string) ($spell[$top]['Description_lang'] ?? ''), $ctx($top));
                $stats[$description === null ? 'no description' : 'described']++;

                $cd = $cooldowns[$top] ?? [];
                $cooldownMs = max((int) ($cd['RecoveryTime'] ?? 0), (int) ($cd['CategoryRecoveryTime'] ?? 0));
                $cast = (int) ($castTimes[(int) ($m['CastingTimeIndex'] ?? 0)]['Base'] ?? 0);
                $range = (float) ($ranges[(int) ($m['RangeIndex'] ?? 0)]['RangeMax_0'] ?? 0);
                $cost = $power[$top] ?? null;
                $costText = null;
                if ($cost) {
                    $word = self::POWER[(int) $cost['PowerType']] ?? null;
                    if ((float) $cost['PowerCostPct'] > 0 && $word) {
                        $costText = rtrim(rtrim(number_format((float) $cost['PowerCostPct'], 1), '0'), '.')."% of base {$word}";
                    } elseif ((int) $cost['ManaCost'] > 0 && $word) {
                        // Rage is stored in tenths: Disarm's 200 is the 20 Rage the game shows.
                        $amount = (int) $cost['PowerType'] === 1 ? (int) $cost['ManaCost'] / 10 : (int) $cost['ManaCost'];
                        $costText = rtrim(rtrim(number_format($amount, 1, '.', ''), '0'), '.').' '.$word;
                    }
                }
                $icon = (int) ($m['SpellIconFileDataID'] ?? 0);
                if ($icon === 0) {
                    foreach ($ids as $id) {
                        $icon = $icon ?: (int) ($misc[$id]['SpellIconFileDataID'] ?? 0);
                    }
                }
                $talent = collect($ranks)->first(fn ($r) => $r['talent']);
                // A talent's level in the tables is not when a player gets it (Ice Block's says 4), and its
                // trained ranks come later (Mortal Strike's second at 48), so a talent ability shows none.
                $levelsSeen = array_filter(array_map($level, array_filter($ids, fn ($id) => ! isset($talentRank[$id]))));
                if (collect($ranks)->contains(fn ($r) => $r['talent'])) {
                    $levelsSeen = [];
                }

                $list[] = [
                    'name' => $n,
                    'spell_id' => $top,
                    'ranks' => count($ids),
                    'level' => $levelsSeen ? min($levelsSeen) : null,
                    'tree' => ($talent ?? $ranks[$top])['tree'],
                    'talent' => $talent !== null,
                    'passive' => (bool) ($attr0($top) & 0x40),
                    'category' => $category,
                    'cooldown' => $cooldownMs > 0 ? $cooldownMs / 1000 : null,
                    'cast' => $cast > 0 ? $cast / 1000 : 0,
                    'range' => $range > 5 ? $range : null,
                    'cost' => $costText,
                    'icon' => $icon ?: null,
                    'description' => $description,
                ];
                $stats['abilities']++;
                $stats['categories'][$category] = ($stats['categories'][$category] ?? 0) + 1;
            }
            usort($list, fn ($a, $b) => [$a['level'] ?? 99, $a['name']] <=> [$b['level'] ?? 99, $b['name']]);
            $out[] = ['slug' => $classes[$classId]['slug'], 'name' => $classes[$classId]['name'], 'abilities' => $list];
        }
        usort($out, fn ($a, $b) => $a['name'] <=> $b['name']);

        $build = $this->build();
        $path = base_path('data/forever/abilities.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'build' => $build,
            'source' => 'wago.tools DB2, product wowf',
            'generated' => now()->toDateString(),
            'classes' => $out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info("Wrote {$path} (build {$build})");
        $this->line("  {$stats['abilities']} abilities over ".count($out).' classes; '.$stats['described'].' with a description, '.$stats['no description'].' without');
        $this->line('  categories: '.json_encode($stats['categories']));
        $this->line('  left out: '.json_encode($dropped));
        $this->line('  left out as the effect of a talent or another spell: '.implode(', ', array_keys($proced)));
        $this->line('  retail curation carried by spell id ('.count($stats['by id from retail']).'): '.implode(', ', array_unique($stats['by id from retail'])));

        if ($this->option('icons')) {
            $this->icons(collect($out)->flatMap(fn ($c) => array_column($c['abilities'], 'icon'))->filter()->unique()->all());
        }

        return self::SUCCESS;
    }

    /** @return array{0: array<int, string>, 1: array<int, string>} effect and aura number => retail's name */
    private function retailEffectNames(): array
    {
        $effects = [];
        $auras = [];
        foreach (glob(base_path('data/spelldata/raw/*.txt')) as $path) {
            foreach (file($path) as $line) {
                if (! preg_match('/^#\d+\s+\(id=\d+\)\s*:\s*(.+?)\s\((\d+)\)(?:\s*\|\s*(.+?)\s\((\d+)\))?/', $line, $m)) {
                    continue;
                }
                $effects[(int) $m[2]][$m[1]] = ($effects[(int) $m[2]][$m[1]] ?? 0) + 1;
                // "Attributes: ..." and "Unknown(645)" ride in the same slot on some lines; they are not auras.
                if (! empty($m[3]) && ! str_starts_with($m[3], 'Attributes:') && ! str_starts_with($m[3], 'Unknown')) {
                    $auras[(int) $m[4]][$m[3]] = ($auras[(int) $m[4]][$m[3]] ?? 0) + 1;
                }
            }
        }
        $top = fn (array $counts) => array_map(function ($c) {
            arsort($c);

            return array_key_first($c);
        }, $counts);

        return [$top($effects), $top($auras)];
    }

    private function classFromMask(int $mask): ?int
    {
        for ($i = 0; $i < 12; $i++) {
            if ($mask === (1 << $i)) {
                return $i + 1;
            }
        }

        return null;
    }

    /** @return \Generator<int, array<string, string>> */
    private function rows(string $table): \Generator
    {
        $h = fopen("{$this->dir}/{$table}.csv", 'r');
        $head = fgetcsv($h, escape: '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($h, escape: '')) !== false) {
            if (count($r) === count($head)) {
                yield array_combine($head, $r);
            }
        }
        fclose($h);
    }

    /** One row per key; for tables with difficulty variants, the base (DifficultyID 0) row. */
    private function keyed(string $table, string $key): array
    {
        $out = [];
        foreach ($this->rows($table) as $r) {
            if (isset($r['DifficultyID']) && $r['DifficultyID'] !== '0') {
                continue;
            }
            $out[(int) $r[$key]] ??= $r;
        }

        return $out;
    }

    private function column(string $table, string $key, string $value): array
    {
        $out = [];
        foreach ($this->rows($table) as $r) {
            $out[(int) $r[$key]] = $r[$value];
        }

        return $out;
    }

    private function download(): bool
    {
        @mkdir($this->dir, 0775, true);
        foreach (self::TABLES as $t) {
            $ok = false;
            // SpellEffect sometimes comes back as a Cloudflare 525 (docs/forever/market-research-2026-11-07.md): retry.
            for ($try = 1; $try <= 3 && ! $ok; $try++) {
                $res = Http::timeout(120)->get("https://wago.tools/db2/{$t}/csv", ['product' => 'wowf']);
                $ok = $res->successful() && str_contains(strtok($res->body(), "\n"), 'ID');
                if ($ok) {
                    file_put_contents("{$this->dir}/{$t}.csv", $res->body());
                }
            }
            $this->line(($ok ? '  got ' : '  FAILED ').$t);
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function build(): string
    {
        try {
            return (string) (Http::timeout(20)->get('https://wago.tools/api/builds/wowf/latest')->json('version') ?? 'unknown');
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Self-hosted like retail's icons (never hotlinked): Blizzard's icon server takes the file id. */
    private function icons(array $fileIds): void
    {
        $dir = storage_path('app/public/forever-icons');
        @mkdir($dir, 0775, true);
        $got = $had = 0;
        $refused = [];
        $fetch = function (string $key, string $path): bool {
            $res = Http::timeout(20)->get("https://render.worldofwarcraft.com/us/icons/56/{$key}.jpg");
            if ($res->successful() && str_starts_with((string) $res->header('Content-Type'), 'image/')) {
                file_put_contents($path, $res->body());

                return true;
            }

            return false;
        };
        foreach ($fileIds as $id) {
            $path = "{$dir}/{$id}.jpg";
            if (is_file($path)) {
                $had++;
            } elseif ($fetch((string) $id, $path)) {
                $got++;
            } else {
                $refused[$id] = true;
            }
        }
        // A few ids get a 403 (Lesser Heal's, Faerie Fire's) while the same icon by its name is served.
        // The name comes from the community listfile ("135929;interface/icons/spell_holy_lesserheal.blp").
        $byName = 0;
        if ($refused && ($listfile = $this->option('listfile')) && is_file($listfile)) {
            $h = fopen($listfile, 'r');
            while ($refused && ($line = fgets($h)) !== false) {
                [$id, $file] = array_pad(explode(';', trim($line), 2), 2, '');
                if (isset($refused[(int) $id]) && preg_match('#^interface/icons/(.+)\.blp$#i', $file, $m)
                    && $fetch(strtolower($m[1]), "{$dir}/{$id}.jpg")) {
                    unset($refused[(int) $id]);
                    $byName++;
                }
            }
            fclose($h);
        }
        foreach (array_keys($refused) as $id) {
            $this->warn("  no icon for file {$id}".($this->option('listfile') ? '' : ' (try --listfile)'));
        }
        $this->info("Icons: {$got} fetched, {$byName} by name, {$had} already here, ".count($refused).' missing');
    }
}
