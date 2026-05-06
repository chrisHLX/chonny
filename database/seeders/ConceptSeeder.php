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
            'data/wowconcepts.json',
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
        $this->attachLolAxes();
        $this->attachWowAxes();
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

    private function attachLolAxes(): void
    {
        $gamesCategory = Category::where('name', 'Games')->first();
        $lolSubject = Subject::where('name', 'League of Legends')->first();

        if (! $gamesCategory || ! $lolSubject) {
            $this->command->warn('⚠️ Games category or League of Legends subject not found — skipping axis links.');
            return;
        }

        $axesByName = $gamesCategory->axes()->pluck('id', 'name');

        $map = [
            'Laning Phase'               => ['Control', 'Execution', 'Information'],
            'Macro Play'                 => ['Decision', 'Control', 'Adaptation'],
            'Micro Mechanics'            => ['Execution'],
            'Itemization'                => ['Decision', 'Resources'],
            'Teamfighting'               => ['Decision', 'Execution', 'Control'],
            'Map Awareness'              => ['Information', 'Control'],
            'Champion Knowledge'         => ['Decision', 'Adaptation'],
            'Drafting & Strategy'        => ['Decision', 'Adaptation'],
            'Jungle Pathing & Objectives'=> ['Decision', 'Control', 'Information'],
            'Mindset & Improvement'      => ['Adaptation'],
        ];

        foreach ($map as $conceptName => $axisNames) {
            $concept = Concept::where('name', $conceptName)
                ->where('subject_id', $lolSubject->id)
                ->first();

            if (! $concept) {
                $this->command->warn("⚠️ LoL concept not found: {$conceptName}");
                continue;
            }

            $axisIds = collect($axisNames)
                ->map(fn ($name) => $axesByName[$name] ?? null)
                ->filter()
                ->values()
                ->toArray();

            $concept->axes()->syncWithoutDetaching($axisIds);
        }

        $this->command->info('✅ LoL concept-axis links attached.');
    }

    private function attachWowAxes(): void
    {
        $gamesCategory = Category::where('name', 'Games')->first();
        $wowSubject = Subject::where('name', 'World of Warcraft: The War Within')->first();

        if (! $gamesCategory || ! $wowSubject) {
            $this->command->warn('⚠️ Games category or World of Warcraft subject not found — skipping axis links.');
            return;
        }

        $axesByName = $gamesCategory->axes()->pluck('id', 'name');

        $map = [
            'Role Fundamentals'   => ['Decision', 'Execution'],
            'Crowd Control'       => ['Control', 'Decision'],
            'Cooldown Management' => ['Decision', 'Resources'],
            'Positioning'         => ['Control', 'Execution', 'Adaptation'],
            'Target Switching'    => ['Decision', 'Execution'],
            'Awareness & Tracking'=> ['Information', 'Adaptation'],
            'Team Composition'    => ['Decision', 'Adaptation'],
        ];

        foreach ($map as $conceptName => $axisNames) {
            $concept = Concept::where('name', $conceptName)
                ->where('subject_id', $wowSubject->id)
                ->first();

            if (! $concept) {
                $this->command->warn("⚠️ WoW concept not found: {$conceptName}");
                continue;
            }

            $axisIds = collect($axisNames)
                ->map(fn ($name) => $axesByName[$name] ?? null)
                ->filter()
                ->values()
                ->toArray();

            $concept->axes()->syncWithoutDetaching($axisIds);
        }

        $this->command->info('✅ WoW concept-axis links attached.');
    }
}
