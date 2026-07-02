<?php

namespace Database\Seeders;

use App\Models\Archetype;
use App\Models\Category;
use Illuminate\Database\Seeder;

class ArchetypeSeeder extends Seeder
{
    public function run(): void
    {
        $archetypes = [
            [
                'key'         => 'strategic_controller',
                'label'       => 'Strategic Controller',
                'description' => 'Thinks in systems, positioning, and long-term control. Understands the game at a structural level but may underuse direct pressure or fail to convert advantages.',
                'signals'     => ['high_strategic_awareness', 'high_control_orientation', 'low_pressure_orientation', 'high_patience'],
            ],
            [
                'key'         => 'reactive_survivor',
                'label'       => 'Reactive Survivor',
                'description' => 'Responds well to danger and stays alive, but often plays from behind. Tends to wait for the enemy to make mistakes rather than creating pressure.',
                'signals'     => ['high_defensive_discipline', 'low_pressure_orientation', 'low_aggression', 'high_reactivity'],
            ],
            [
                'key'         => 'aggressive_forcer',
                'label'       => 'Aggressive Forcer',
                'description' => 'Creates action constantly and applies heavy pressure. May overcommit or act before conditions are favourable, leading to wasted resources.',
                'signals'     => ['high_aggression', 'high_pressure_orientation', 'low_patience', 'low_defensive_discipline'],
            ],
            [
                'key'         => 'mechanical_grinder',
                'label'       => 'Mechanical Grinder',
                'description' => 'Relies on execution and repetition. Strong at performing known patterns under pressure but may lack strategic depth or struggle when the plan breaks.',
                'signals'     => ['high_independence', 'high_structure_preference', 'low_adaptability', 'low_creativity'],
            ],
            [
                'key'         => 'adaptive_opportunist',
                'label'       => 'Adaptive Opportunist',
                'description' => 'Reads evolving situations and acts on openings as they appear. Effective in chaotic games but may lack the discipline to execute structured setups.',
                'signals'     => ['high_adaptability', 'high_creativity', 'low_structure_preference', 'high_kill_instinct'],
            ],
            [
                'key'         => 'patient_setup_artist',
                'label'       => 'Patient Setup Artist',
                'description' => 'Waits for the right moment and executes deliberate, planned sequences. Strong in structured environments but may miss windows that require improvisation.',
                'signals'     => ['high_patience', 'high_structure_preference', 'low_risk_tolerance', 'high_control_orientation'],
            ],
        ];

        foreach ($archetypes as $data) {
            Archetype::firstOrCreate(
                ['key' => $data['key']],
                [
                    'label'       => $data['label'],
                    'description' => $data['description'],
                    'signals'     => $data['signals'],
                ]
            );
        }

        // Attach all archetypes to the Games category
        $gamesCategory = Category::where('name', 'Games')->first();
        if ($gamesCategory) {
            $archetypeIds = Archetype::pluck('id')->toArray();
            $gamesCategory->archetypes()->syncWithoutDetaching($archetypeIds);
            $this->command->info('✅ Archetypes attached to Games category.');
        } else {
            $this->command->warn('⚠️  Games category not found — archetypes seeded but not attached to a category.');
        }

        $this->command->info('✅ ' . count($archetypes) . ' archetypes seeded.');
    }
}
