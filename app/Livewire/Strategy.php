<?php

namespace App\Livewire;

use App\Http\Services\MatchupProfileService;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Strategy, shown in arena — a teaser.
 *
 * WHAT IT IS FOR, AND WHICH WAY ROUND. Two directions were on the table: use classical doctrine
 * to teach arena, or use arena to make strategic ideas concrete. This is the second. The reason
 * is specific to this project rather than a matter of taste: we hold ground truth for arena —
 * real cooldowns, real durations, a model where every claim carries its provenance — and none
 * at all for Sun Tzu. Putting the checkable half underneath is what stops the abstract half
 * floating away.
 *
 * THE PRECEDENT IS ALREADY IN THE MODEL. Part 5 says "a good go is a zugzwang, not a burst" —
 * a chess term imported to name an arena mechanism, and one of the sharpest lines in the
 * document. Note the order it happened in: the mechanism was derived first, and the name was
 * applied afterwards because it fitted exactly. Every concept here follows that order. A name
 * that arrives before the mechanism is decoration.
 *
 * DELIBERATELY SEPARATE FROM THE GUIDES. The machine guides were cut by 84% on 2026-09-23
 * because their reader said the reasoning buried the mechanics. A doctrine layer is more prose,
 * so it does not go near them. Guides stay mechanical; this is its own page for somebody reading
 * to understand rather than to practise.
 *
 * SPELLS ARE RESOLVED, NEVER TYPED. Every ability in a sequence is looked up by name in the
 * committed matchup profiles, which carry the real icon and external id. A name that does not
 * resolve renders as plain text and is listed by {@see unresolved()} rather than shipping a
 * broken icon — the same "flag, don't guess" rule the rest of the project runs on.
 */
class Strategy extends Component
{
    private const SOURCE = 'data/strategy/concepts.json';

    #[Computed]
    public function document(): array
    {
        $path = base_path(self::SOURCE);

        if (! File::exists($path)) {
            return ['concepts' => []];
        }

        return json_decode(File::get($path), true) ?: ['concepts' => []];
    }

    /**
     * The document with every sequence step resolved to a real ability.
     *
     * @return array<int, array>
     */
    #[Computed]
    public function concepts(): array
    {
        $profiles = app(MatchupProfileService::class);
        $specs = $this->specsByRef();
        $cache = [];

        $concepts = $this->document()['concepts'] ?? [];

        foreach ($concepts as $i => $concept) {
            foreach ($concept['sequence'] ?? [] as $j => $step) {
                $ref = $step['spec'] ?? '';
                [$classSlug, $specSlug] = array_pad(explode('/', $ref), 2, '');

                $cache[$ref] ??= $profiles->read($classSlug, $specSlug);
                $resolved = $this->findSpell($cache[$ref], $step['spell'] ?? '');

                $concepts[$i]['sequence'][$j]['icon'] = $resolved['icon'] ?? null;
                $concepts[$i]['sequence'][$j]['spellId'] = $resolved['spellId'] ?? null;
                $concepts[$i]['sequence'][$j]['drCategory'] = $resolved['drCategory'] ?? null;
                $concepts[$i]['sequence'][$j]['cooldown'] = $resolved['cooldown'] ?? null;
                $concepts[$i]['sequence'][$j]['duration'] = $resolved['duration'] ?? null;
                $concepts[$i]['sequence'][$j]['resolved'] = $resolved !== null;
                $concepts[$i]['sequence'][$j]['specName'] = $specs[$ref] ?? $ref;
                $concepts[$i]['sequence'][$j]['classSlug'] = $classSlug;
            }
        }

        return $concepts;
    }

    /**
     * Any ability a sequence names that the data does not have.
     *
     * Surfaced on the page rather than swallowed. A sequence quietly missing a step is the
     * failure mode that made "ability no longer found" worth rendering in the guides too.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function unresolved(): array
    {
        $missing = [];

        foreach ($this->concepts() as $concept) {
            foreach ($concept['sequence'] ?? [] as $step) {
                if (! ($step['resolved'] ?? false)) {
                    $missing[] = $step['spell'].' ('.$step['spec'].')';
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * External spell id => internal id, so an ability can link to its own page. One query, and
     * only for what actually resolved.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function spellLinks(): array
    {
        $external = [];

        foreach ($this->concepts() as $concept) {
            foreach ($concept['sequence'] ?? [] as $step) {
                if (! empty($step['spellId'])) {
                    $external[] = (int) $step['spellId'];
                }
            }
        }

        if ($external === []) {
            return [];
        }

        return Spell::where('patch_id', Patch::where('is_current', true)->value('id'))
            ->whereIn('spell_id', array_unique($external))
            ->pluck('id', 'spell_id')
            ->all();
    }

    private function findSpell(?array $profile, string $name): ?array
    {
        if (! $profile || $name === '') {
            return null;
        }

        foreach (['control', 'offensive', 'answers', 'mobility', 'interrupts'] as $bucket) {
            foreach ($profile[$bucket] ?? [] as $row) {
                if ($row['name'] === $name) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * "rogue/subtlety" => "Subtlety Rogue", for the line under each step.
     *
     * @return array<string, string>
     */
    private function specsByRef(): array
    {
        $map = [];

        foreach (Specialization::with('gameClass')->get() as $spec) {
            if ($spec->gameClass) {
                $map[$spec->gameClass->slug.'/'.$spec->slug] = $spec->name.' '.$spec->gameClass->name;
            }
        }

        return $map;
    }

    public function mount(): void
    {
        PageViewEvent::log('strategy');
    }

    public function render()
    {
        return view('livewire.strategy', [
            'document' => $this->document(),
            'concepts' => $this->concepts(),
            'unresolved' => $this->unresolved(),
            'spellLinks' => $this->spellLinks(),
        ])->layout('layouts.app', [
            'title' => 'Strategy, shown in arena | MindCollector',
            'description' => 'Strategic ideas — reconnaissance by fire, zugzwang, patience inside a window — '
                .'each one next to the exact arena sequence that is an instance of it, with real cooldowns '
                .'and real abilities.',
        ]);
    }
}
