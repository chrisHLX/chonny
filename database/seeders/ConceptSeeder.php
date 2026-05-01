<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subject;
use App\Models\Concept;
use Illuminate\Support\Facades\File;

class ConceptSeeder extends Seeder
{
    public function run(): void
    {
        $conceptFiles = [
            'data/concepts.json',
            'data/newconcepts.json',
        ];

        $loadedCount = 0;
        $skippedCount = 0;

        foreach ($conceptFiles as $filePath) {
            $jsonPath = database_path($filePath);

            if (!File::exists($jsonPath)) {
                $this->command->warn("⚠️ Concept file not found: {$jsonPath}");
                continue;
            }

            $concepts = json_decode(File::get($jsonPath), true);

            foreach ($concepts as $data) {
                $subject = Subject::where('name', $data['subject'])->first();

                if (!$subject) {
                    $this->command->warn("⚠️ Subject not found for concept: {$data['name']}");
                    $skippedCount++;
                    continue;
                }

                $concept = Concept::firstOrCreate(
                    ['name' => $data['name'], 'subject_id' => $subject->id],
                    ['description' => $data['description'] ?? null]
                );

                if ($concept->wasRecentlyCreated) {
                    $loadedCount++;
                }
            }
        }

        $this->command->info("✅ Concepts seeded! Created: {$loadedCount}, Skipped: {$skippedCount}");

        $this->attachSc2Axes();
    }

    private function attachSc2Axes(): void
    {
        $gamesCategory = Category::where('name', 'Games')->first();
        $sc2Subject = Subject::where('name', 'StarCraft 2')->first();

        if (! $gamesCategory || ! $sc2Subject) {
            $this->command->warn('⚠️ Games category or StarCraft 2 subject not found — skipping axis links.');
            return;
        }

        $axesByName = $gamesCategory->axes()->pluck('id', 'name');

        $map = [
            'Army'         => ['Execution', 'Decision', 'Adaptation'],
            'Build Orders' => ['Decision', 'Resources', 'Adaptation'],
            'Economy'      => ['Resources', 'Decision'],
            'Map Control'  => ['Control', 'Information', 'Decision'],
            'Mechanics'    => ['Execution'],
            'Scouting'     => ['Information', 'Decision'],
            'Strategy'     => ['Decision', 'Adaptation'],
            'Tactics'      => ['Decision', 'Execution'],
        ];

        foreach ($map as $conceptName => $axisNames) {
            $concept = Concept::where('name', $conceptName)
                ->where('subject_id', $sc2Subject->id)
                ->first();

            if (! $concept) {
                $this->command->warn("⚠️ SC2 concept not found: {$conceptName}");
                continue;
            }

            $axisIds = collect($axisNames)
                ->map(fn ($name) => $axesByName[$name] ?? null)
                ->filter()
                ->values()
                ->toArray();

            $concept->axes()->syncWithoutDetaching($axisIds);
        }

        $this->command->info('✅ SC2 concept-axis links attached.');
    }
}
