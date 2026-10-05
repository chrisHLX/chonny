<?php

namespace App\Quiz\Wow;

use App\Console\Commands\BuildGoCooldowns;
use App\Quiz\QuizQuestion;
use Illuminate\Support\Facades\File;
use Random\Randomizer;

/**
 * The arena basics check: one question per basic, so the result says which basics a player has
 * and which to work on (2026-10-05).
 *
 * Why it exists: two new players in Chriso's Paladin games (match-review-analysis.md, 5 Oct) won the
 * one go they played to a plan and lost the rest to basics nobody had told them — cooldowns pressed
 * apart, control on the player they were hitting, the player killing them never controlled. The
 * comp page's "How to play it" tells a new player the basics; this checks they took them in; the
 * games they upload show whether they do them.
 *
 * The basics are the comp page's list (comp-playbook.blade.php), one question each. Where the data
 * can carry the question it is built from the player's own spec, so it asks about buttons they
 * press: which of THEIR control holds through damage, which of THEIR defensives to keep. Where it
 * cannot (what a go is, when to swap), the question is authored, and every authored answer comes
 * from an [OBS] or [DER] claim in the arena model, never a [HYP] (system-integration.md, Layer 2).
 * Each basic names the brain.md section it rests on, so a disputed answer has a place to be argued.
 *
 * Facts it relies on, and where they were settled:
 *  - DR: full, then half, then immune, reset after 20s without one (dr-categories-reference.md,
 *    corrected by the domain expert 2026-08-11; CcFormulaService's 20s horizon).
 *  - Only a spell with a cast time can be kicked (spells.cast_type).
 */
class BasicsCheck
{
    /**
     * The basics, in the order they are asked and shown. `lesson` is what the result screen says
     * when the question was missed; `brain` is the arena model's section.
     */
    public const BASICS = [
        'goes' => ['title' => 'A game is a series of goes', 'brain' => 'answer-pool',
            'lesson' => 'A go is your team committing together: their healer locked, your big cooldowns pressed at once, on one player. Between goes, you set up the next one.'],
        'together' => ['title' => 'Press your big buttons together', 'brain' => 'zugzwang',
            'lesson' => 'Cooldowns pressed a few seconds apart give their healer time to answer each one. Pressed together, they land as one burst.'],
        'healer_first' => ['title' => 'Their healer first, then the damage', 'brain' => 'allocation',
            'lesson' => 'Land your crowd control on their healer, and press your cooldowns while it holds. A free healer heals through your burst.'],
        'dr' => ['title' => 'The same kind of control gets shorter', 'brain' => 'clock',
            'lesson' => 'A second control of the same kind on the same player lasts half as long, a third does nothing, until 20 seconds pass. Chain different kinds.'],
        'one_defensive' => ['title' => 'One defensive at a time', 'brain' => 'overlap',
            'lesson' => 'Two defensives spent on one go leave you nothing for the next. Press one and keep the other.'],
        'switch' => ['title' => 'If the target will not die, change something', 'brain' => 'answer-pool',
            'lesson' => 'A kill depends on what the target can still press. Swap to whoever has the fewest answers left, or back off until your cooldowns return.'],
        'kicks' => ['title' => 'Kick what can be kicked', 'brain' => 'setup',
            'lesson' => 'A kick only stops a spell being cast. Kick their healer\'s heals in your go and the control they cast on your healer; instant control needs another answer.'],
        'los' => ['title' => 'Use line of sight', 'brain' => 'gaps',
            'lesson' => 'A spell needs line of sight to its target. Behind a pillar you cannot be cast on, and your healer cannot heal you either.'],
    ];

    private const LOCKING = ['Stun', 'Silence'];

    /**
     * Control that only lands on some creature types, so a question about players never offers
     * it (guide-writing.md: "some spells cannot target a player at all"). Hibernate and Scare
     * Beast reach a Druid only in an animal form, which is too much to ask a new player to know.
     */
    private const NOT_ON_PLAYERS = ['Scare Beast', 'Hibernate', 'Banish', 'Turn Evil', 'Shackle Undead', 'Shackle Horror', 'Control Undead', 'Mind Control'];

    private const BREAKING = ['Incapacitate', 'Disorient'];

    /** Seconds between the two controls in the DR question: inside the 20s window. */
    private const DR_GAP = 8;

    private Randomizer $random;

    /**
     * @param  array<int, WowAbility>  $abilities  the player's spec
     * @param  array<int, WowAbility>  $ccPool  crowd control across the game
     * @param  ?int  $externalSpecId  for the measured go buttons (wow:go-cooldowns)
     * @param  array<int, array{label: string, class: string}>  $specNames  external spec id => names, for a partner
     * @param  ?array  $goData  the go-cooldowns file's contents; read from disk when null (tests pass it)
     */
    public function __construct(
        private readonly string $specLabel,
        array $abilities,
        array $ccPool,
        private readonly ?int $externalSpecId = null,
        private readonly bool $healer = false,
        private readonly array $specNames = [],
        private readonly ?string $className = null,
        ?Randomizer $random = null,
        private readonly ?array $goData = null,
    ) {
        $this->random = $random ?? new Randomizer;
        $onPlayers = fn (WowAbility $a) => ! in_array($a->name, self::NOT_ON_PLAYERS, true);
        $this->abilities = array_values(array_filter($abilities, $onPlayers));
        $this->ccPool = array_values(array_filter($ccPool, $onPlayers));
    }

    /** @var array<int, WowAbility> */
    private array $abilities;

    /** @var array<int, WowAbility> */
    private array $ccPool;

    /** @return array<int, QuizQuestion> one per basic, in BASICS order */
    public function build(): array
    {
        $questions = [];

        foreach (array_keys(self::BASICS) as $key) {
            $question = match ($key) {
                'goes' => $this->goes(),
                'together' => $this->together(),
                'healer_first' => $this->healerFirst(),
                'dr' => $this->dr(),
                'one_defensive' => $this->oneDefensive(),
                'switch' => $this->switchTarget(),
                'kicks' => $this->kicks(),
                'los' => $this->lineOfSight(),
            };

            if ($question) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /** The basic a question tests, from its type ("basic:dr" → "dr"). */
    public static function basicOf(string $type): ?string
    {
        return str_starts_with($type, 'basic:') ? substr($type, 6) : null;
    }

    private function goes(): QuizQuestion
    {
        return $this->text('goes', 'What is a "go" in arena?',
            'Your team locks their healer in crowd control and presses its big cooldowns together on one player',
            ['Any time one player presses their biggest cooldown on whoever is closest', 'The opening seconds, when everyone runs out of the gates and picks a target', 'Chasing a low player with everything you have until they die or get away'],
            'A game is a run of goes, theirs and yours. A go is your team committing at once: their healer locked, your cooldowns together, one target. Between goes, you set up the next.');
    }

    /**
     * Built from play when both sides of it are measured: the player's own go button, and a
     * partner's from another class, both from wow:go-cooldowns. A healer, or an unmeasured spec,
     * gets the general form.
     */
    private function together(): QuizQuestion
    {
        $why = 'Cooldowns a few seconds apart give their healer time to answer each one. Pressed together they land as one burst, while the healer is locked.';
        $mine = $this->healer ? null : $this->goButton($this->externalSpecId);
        $partner = $mine ? $this->partnerButton() : null;

        if ($mine && $partner) {
            return $this->text('together',
                'Your partner, '.$this->article($partner['spec'])." {$partner['spec']}, presses {$partner['button']} to start your go. Your {$mine->name} is ready. When do you press it?",
                'Now, so both land at the same time',
                ["When {$partner['button']} ends, to keep the damage going", 'Save it, for the next go', 'Once the target is under half health'],
                $why, $mine);
        }

        return $this->text('together', 'Your two damage dealers both have their big cooldowns ready. When should they press them?',
            'At the same moment, while their healer is crowd controlled',
            ['One after the other, so one is always running', 'Each as soon as it comes off cooldown', 'Only once the target is already low'],
            $why);
    }

    private function healerFirst(): QuizQuestion
    {
        // The example is control a healer usually gets: an incapacitate or disorient where the spec
        // has one, since its stuns are often kept for the kill target.
        $cc = $this->pick(array_filter($this->abilities, fn (WowAbility $a) => in_array($a->drCategory, self::BREAKING, true)))
            ?? $this->pick(array_filter($this->abilities, fn (WowAbility $a) => in_array($a->drCategory, self::LOCKING, true)));
        $with = $cc ? " Your {$cc->name} is made for it." : '';

        return $this->text('healer_first', 'Your team is about to go on their Warrior. Their healer can act freely. What do you do first?',
            'Crowd control their healer, then press your cooldowns',
            ['Press your cooldowns now, and control their healer later', 'Crowd control the Warrior, so he cannot run away', 'Wait for their healer to run out of mana first'],
            'A healer who can act heals through your burst or saves the target. Land the control on the healer first, and your damage goes in while it holds.'.$with, $cc);
    }

    /** Two controls of one kind on one player, inside the window: the second is halved. */
    private function dr(): ?QuizQuestion
    {
        $kinds = [...self::LOCKING, ...self::BREAKING];
        $first = $this->pick(array_filter($this->abilities, fn (WowAbility $a) => in_array($a->drCategory, $kinds, true)));
        $pool = $first ? array_filter($this->ccPool, fn (WowAbility $a) => $a->drCategory === $first->drCategory && $a->name !== $first->name && $a->pvpDuration) : [];
        $second = $this->pick($pool);

        if (! $first || ! $second) {
            return null;
        }

        $kind = strtolower($first->drCategory);
        $full = $this->seconds($second->pvpDuration);
        $half = $this->seconds($second->pvpDuration / 2);

        return $this->text('dr',
            "You land {$first->name} on their healer. ".self::DR_GAP." seconds later your partner lands {$second->name} on her, another {$kind}. How long does {$second->name} last?",
            "{$half}, half its time",
            ["{$full}, its full time", 'Not at all: she is immune', $this->seconds($second->pvpDuration * 2).', twice as long'],
            "Control of one kind shares diminishing returns: the second lasts half as long, the third does nothing, until 20 seconds pass without one. To keep her locked, follow a {$kind} with a different kind.", $first);
    }

    private function oneDefensive(): QuizQuestion
    {
        $defensives = array_values(array_filter($this->abilities, fn (WowAbility $a) => $a->defensive && ! $a->offensive && $a->cooldown));
        $two = $this->distinct($this->shuffle($defensives), 2, null);
        $why = 'Two defensives spent on one go leave nothing for the next one. Press one; your healer and the one you kept are your backup.';

        if (count($two) === 2) {
            return $this->text('one_defensive',
                "Their go starts on you. You have {$two[0]->name} and {$two[1]->name} ready, and your healer is free. What do you press?",
                'One of them, and keep the other for their next go',
                ['Both at once, to be sure', 'Both, one straight after the other', 'Gladiator\'s Medallion first, then both'],
                $why, $two[0]);
        }

        return $this->text('one_defensive', 'Their go starts on you. You have two defensives ready, and your healer is free. What do you press?',
            'One of them, and keep the other for their next go',
            ['Both at once, to be sure', 'Both, one straight after the other', 'Gladiator\'s Medallion first, then both'],
            $why);
    }

    /**
     * Chriso's correction to the stored bank (system-integration.md §2): swapping to the healer and
     * resetting are both right, in different circumstances. The question states the circumstance
     * that makes one of them right: the cooldowns are still running and the healer has nothing left.
     */
    private function switchTarget(): QuizQuestion
    {
        return $this->text('switch',
            'Your go on their Feral did not kill him. He still has Survival Instincts and Barkskin ready. Their healer has already used her trinket and her big defensive. Your damage cooldowns have a few seconds left. What do you do?',
            'Swap to their healer while your cooldowns last',
            ['Keep hitting the Feral until your cooldowns run out', 'Stop attacking and wait for the next go', 'Crowd control the Feral and keep hitting him'],
            'A kill depends on what the target can still press. The Feral has two answers left and the healer none, so the damage you still have goes where it can kill. With your cooldowns already spent, backing off to set up the next go would be right instead.');
    }

    /** One control with a cast time, three instant: only the cast can be kicked. */
    private function kicks(): ?QuizQuestion
    {
        $cc = array_filter($this->ccPool, fn (WowAbility $a) => in_array($a->drCategory, [...self::LOCKING, ...self::BREAKING], true));
        $correct = $this->pick(array_filter($cc, fn (WowAbility $a) => $a->castType === 'cast'));
        $wrong = $this->distinct($this->shuffle(array_filter($cc, fn (WowAbility $a) => $a->castType === 'instant')), 3, $correct);

        if (! $correct || count($wrong) < 3) {
            return null;
        }

        return $this->icons('kicks', 'Which of these can a kick stop?', $correct, $wrong,
            "{$correct->name} has a cast time, so a kick during the cast stops it. ".implode(', ', array_map(fn ($a) => $a->name, $wrong)).' are instant: the answer to them is a defensive, a trinket, or not being there.');
    }

    private function lineOfSight(): QuizQuestion
    {
        return $this->text('los', 'Their Mage starts casting Polymorph on you. Your trinket and your kick are both down. What stops it without pressing anything?',
            'Moving behind a pillar, so they cannot see you',
            ['Jumping, so the cast misses you', 'Running towards the Mage to get in range', 'Turning to face away from the Mage'],
            'A spell needs line of sight to its target for the whole cast. Behind a pillar you cannot be cast on, and your healer cannot heal you either, so use it on purpose.');
    }

    // --- helpers -----------------------------------------------------------------------------

    /** A text question. $about puts an ability's icon above it. */
    private function text(string $basic, string $prompt, string $correct, array $wrong, string $explanation, ?WowAbility $about = null): QuizQuestion
    {
        $options = $this->shuffle([
            ['key' => 'right', 'label' => $correct, 'icon' => null],
            ...array_map(fn ($label, $i) => ['key' => "w{$i}", 'label' => $label, 'icon' => null], $wrong, array_keys($wrong)),
        ]);

        return new QuizQuestion('basic:'.$basic, $prompt, $about ? ['label' => $about->name, 'icon' => $about->iconPath()] : null, $options, 'right', $explanation);
    }

    /** A question whose options are abilities, with their icons. */
    private function icons(string $basic, string $prompt, WowAbility $correct, array $wrong, string $explanation): QuizQuestion
    {
        $options = $this->shuffle([
            ['key' => 'right', 'label' => $correct->name, 'icon' => $correct->iconPath()],
            ...array_map(fn (WowAbility $a, $i) => ['key' => "w{$i}", 'label' => $a->name, 'icon' => $a->iconPath()], $wrong, array_keys($wrong)),
        ]);

        return new QuizQuestion('basic:'.$basic, $prompt, null, $options, 'right', $explanation);
    }

    /** The spec's most-pressed go button that is in its kit, counted from play. */
    private function goButton(?int $externalSpecId): ?WowAbility
    {
        $spells = $this->measured($externalSpecId)['spells'] ?? [];
        $byName = collect($this->abilities)->keyBy('name');

        foreach ($spells as $spell) {
            if ($spell['share'] >= 0.5 && $byName->has($spell['name']) && ! $byName[$spell['name']]->drCategory) {
                return $byName[$spell['name']];
            }
        }

        return null;
    }

    /** A measured DPS spec of another class and its top go button, by name. */
    private function partnerButton(): ?array
    {
        $candidates = [];
        // The tags call some control "offensive" (Chaos Nova); a go button is never control.
        $control = array_flip(array_map(fn (WowAbility $a) => $a->name, $this->ccPool));

        foreach (($this->goCooldowns()['specs'] ?? []) as $id => $data) {
            $names = $this->specNames[(int) $id] ?? null;
            $top = collect($data['spells'])->first(fn ($s) => ! isset($control[$s['name']]));

            // Another class (a Fire Mage's partner is not a Frost Mage), measured, and a button
            // the spec presses in nearly every go, so the scenario is the usual one.
            if ($names && $top && $top['share'] >= 0.8 && $names['class'] !== $this->className
                && $this->measured((int) $id)) {
                $candidates[] = ['spec' => $names['label'], 'button' => $top['name']];
            }
        }

        return $this->pick($candidates);
    }

    private function measured(?int $externalSpecId): ?array
    {
        $data = $externalSpecId ? ($this->goCooldowns()['specs'][(string) $externalSpecId] ?? null) : null;

        return $data && $data['goes'] >= 20 && $data['players'] >= 5 ? $data : null;
    }

    private ?array $loaded = null;

    private function goCooldowns(): array
    {
        if ($this->goData !== null) {
            return $this->goData;
        }

        $path = base_path(BuildGoCooldowns::PATH);

        return $this->loaded ??= File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
    }

    /** Up to $n abilities with different names, none named like $not. */
    private function distinct(array $abilities, int $n, ?WowAbility $not): array
    {
        $out = [];

        foreach ($abilities as $a) {
            if (count($out) >= $n) {
                break;
            }
            if (($not && $a->name === $not->name) || isset($out[$a->name])) {
                continue;
            }
            $out[$a->name] = $a;
        }

        return array_values($out);
    }

    private function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items === [] ? null : $items[$this->random->getInt(0, count($items) - 1)];
    }

    private function shuffle(array $items): array
    {
        return $this->random->shuffleArray(array_values($items));
    }

    private function article(string $noun): string
    {
        return in_array(strtolower($noun[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    private function seconds(float $s): string
    {
        return rtrim(rtrim(number_format($s, 1), '0'), '.').'s';
    }
}
