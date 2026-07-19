<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Models\SubjectContextDimension;
use App\Models\SubjectContextOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data only, idempotent (firstOrCreate, matching ConceptSeeder's convention) — never invents
 * anything at runtime, see the "Subject Context Dimensions" note in CLAUDE.md. Poker is
 * deliberately left with zero dimensions to prove that path works cleanly, not an oversight.
 */
class SubjectContextSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSc2();
        $this->seedWow();
        $this->seedLol();

        $this->command?->info('✅ Subject context dimensions seeded successfully!');
    }

    private function seedSc2(): void
    {
        $subject = Subject::where('name', 'StarCraft 2')->first();
        if (!$subject) {
            $this->command?->warn('⚠️ StarCraft 2 subject not found — skipping context dimensions.');
            return;
        }

        $race = $this->dimension($subject->id, 'Race', order: 0);
        $this->options($race->id, ['Terran', 'Zerg', 'Protoss']);
    }

    private function seedWow(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();
        if (!$subject) {
            $this->command?->warn('⚠️ WoW subject not found — skipping context dimensions.');
            return;
        }

        $class = $this->dimension($subject->id, 'Class', order: 0);
        $classOptions = $this->options($class->id, [
            'Warrior', 'Paladin', 'Hunter', 'Rogue', 'Priest', 'Death Knight',
            'Shaman', 'Mage', 'Warlock', 'Monk', 'Druid', 'Demon Hunter', 'Evoker',
        ]);

        $spec = $this->dimension($subject->id, 'Spec', order: 1, parentDimensionId: $class->id);

        // Specs seeded for a few classes only, to prove the hierarchy works — not exhaustive.
        $this->options($spec->id, ['Assassination', 'Outlaw', 'Subtlety'], parentOptionId: $classOptions['Rogue']->id);
        $this->options($spec->id, ['Balance', 'Feral', 'Guardian', 'Restoration'], parentOptionId: $classOptions['Druid']->id);
        $this->options($spec->id, ['Arms', 'Fury', 'Protection'], parentOptionId: $classOptions['Warrior']->id);
    }

    private function seedLol(): void
    {
        $subject = Subject::where('name', 'League of Legends')->first();
        if (!$subject) {
            $this->command?->warn('⚠️ LoL subject not found — skipping context dimensions.');
            return;
        }

        $role = $this->dimension($subject->id, 'Role', order: 0);
        $this->options($role->id, ['Top', 'Jungle', 'Mid', 'ADC', 'Support']);

        // Champion dimension deliberately deferred — 160+ options, add when actually needed.
    }

    private function dimension(int $subjectId, string $name, int $order, ?int $parentDimensionId = null): SubjectContextDimension
    {
        return SubjectContextDimension::firstOrCreate(
            ['subject_id' => $subjectId, 'slug' => Str::slug($name)],
            ['name' => $name, 'order' => $order, 'required' => true, 'parent_dimension_id' => $parentDimensionId]
        );
    }

    /**
     * @return array<string, SubjectContextOption> keyed by option name, for callers that need to
     *         reference a specific created option (e.g. attaching specs to their parent class).
     */
    private function options(int $dimensionId, array $names, ?int $parentOptionId = null): array
    {
        $created = [];

        foreach ($names as $i => $name) {
            $created[$name] = SubjectContextOption::firstOrCreate(
                ['dimension_id' => $dimensionId, 'slug' => Str::slug($name)],
                ['name' => $name, 'order' => $i, 'parent_option_id' => $parentOptionId]
            );
        }

        return $created;
    }
}
