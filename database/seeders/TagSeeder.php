<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tag;
use App\Models\Subject;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map subjects (by name — never by hardcoded ID, since insertion order in
        // SubjectSeeder has changed over time and a hardcoded ID map has previously
        // drifted out of sync, silently tagging the wrong subject) to their tags.
        $subjectTags = [
            'StarCraft 2' => [
                ['name' => 'Zerg', 'type' => 'identity'],
                ['name' => 'Terran', 'type' => 'identity'],
                ['name' => 'Protoss', 'type' => 'identity'],
                ['name' => 'Early Game', 'type' => 'phase'],
                ['name' => 'Midgame', 'type' => 'phase'],
                ['name' => 'Late Game', 'type' => 'phase'],
                ['name' => 'ZvT', 'type' => 'matchup'],
                ['name' => 'ZvP', 'type' => 'matchup'],
                ['name' => 'ZvZ', 'type' => 'matchup'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'League of Legends' => [
                ['name' => 'Top Lane', 'type' => 'role'],
                ['name' => 'Mid Lane', 'type' => 'role'],
                ['name' => 'ADC', 'type' => 'role'],
                ['name' => 'Jungle', 'type' => 'role'],
                ['name' => 'Support', 'type' => 'role'],
                ['name' => 'Champ Name', 'type' => 'identity'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'Medicine' => [
                ['name' => 'Cardiology', 'type' => 'specialty'],
                ['name' => 'Neurology', 'type' => 'specialty'],
                ['name' => 'Pediatrics', 'type' => 'specialty'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'Music' => [
                ['name' => 'Guitar', 'type' => 'instrument'],
                ['name' => 'Piano', 'type' => 'instrument'],
                ['name' => 'Drums', 'type' => 'instrument'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'Ancient History' => [
                ['name' => 'Egypt', 'type' => 'region'],
                ['name' => 'Rome', 'type' => 'region'],
                ['name' => 'Greece', 'type' => 'region'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'Industrial Materials' => [
                ['name' => 'Steel', 'type' => 'material'],
                ['name' => 'Aluminium', 'type' => 'material'],
                ['name' => 'Copper', 'type' => 'material'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
            'Programming' => [
                ['name' => 'PHP', 'type' => 'language'],
                ['name' => 'Python', 'type' => 'language'],
                ['name' => 'JavaScript', 'type' => 'language'],
                ['name' => 'Other', 'type' => 'unspecified'],
            ],
        ];

        // Loop through subjects and their tags
        foreach ($subjectTags as $subjectName => $tags) {
            $subject = Subject::where('name', $subjectName)->first();

            if (! $subject) {
                $this->command->warn("⚠️ Subject not found for tags: {$subjectName}");
                continue;
            }

            foreach ($tags as $tag) {
                Tag::firstOrCreate(
                    [
                        'subject_id' => $subject->id,
                        'name' => $tag['name'],
                    ],
                    [
                        'type' => $tag['type'],
                    ]
                );
            }
        }
    }
}