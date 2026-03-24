<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tag;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map subject IDs to their tags
        $subjectTags = [
            1 => [ // SC2
                ['name' => 'Zerg', 'type' => 'identity'],
                ['name' => 'Terran', 'type' => 'identity'],
                ['name' => 'Protoss', 'type' => 'identity'],
                ['name' => 'Early Game', 'type' => 'phase'],
                ['name' => 'Midgame', 'type' => 'phase'],
                ['name' => 'Late Game', 'type' => 'phase'],
                ['name' => 'ZvT', 'type' => 'matchup'],
                ['name' => 'ZvP', 'type' => 'matchup'],
                ['name' => 'ZvZ', 'type' => 'matchup'],
            ],
            2 => [ // League of Legends
                ['name' => 'Top Lane', 'type' => 'role'],
                ['name' => 'Mid Lane', 'type' => 'role'],
                ['name' => 'ADC', 'type' => 'role'],
                ['name' => 'Jungle', 'type' => 'role'],
                ['name' => 'Support', 'type' => 'role'],
                ['name' => 'Champ Name', 'type' => 'identity'],
            ],
            3 => [ // Medicine
                ['name' => 'Cardiology', 'type' => 'specialty'],
                ['name' => 'Neurology', 'type' => 'specialty'],
                ['name' => 'Pediatrics', 'type' => 'specialty'],
            ],
            4 => [ // Music
                ['name' => 'Guitar', 'type' => 'instrument'],
                ['name' => 'Piano', 'type' => 'instrument'],
                ['name' => 'Drums', 'type' => 'instrument'],
            ],
            5 => [ // Ancient History
                ['name' => 'Egypt', 'type' => 'region'],
                ['name' => 'Rome', 'type' => 'region'],
                ['name' => 'Greece', 'type' => 'region'],
            ],
            6 => [ // Industrial Metals
                ['name' => 'Steel', 'type' => 'material'],
                ['name' => 'Aluminium', 'type' => 'material'],
                ['name' => 'Copper', 'type' => 'material'],
            ],
            7 => [ // Programming
                ['name' => 'PHP', 'type' => 'language'],
                ['name' => 'Python', 'type' => 'language'],
                ['name' => 'JavaScript', 'type' => 'language'],
            ],
        ];

        // Loop through subjects and their tags
        foreach ($subjectTags as $subjectId => $tags) {
            foreach ($tags as $tag) {
                Tag::firstOrCreate(
                    [
                        'subject_id' => $subjectId,
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