<?php

namespace Database\Seeders;

use App\Models\PlayerTrait;
use Illuminate\Database\Seeder;

class TraitsSeeder extends Seeder
{
    public function run(): void
    {
        $traits = [
            [
                'key'         => 'aggression',
                'name'        => 'Aggression',
                'category'    => 'tempo',
                'sort_order'  => 0,
                'description' => 'Preference for forcing action, creating pressure, and taking initiative.',
            ],
            [
                'key'         => 'patience',
                'name'        => 'Patience',
                'category'    => 'tempo',
                'sort_order'  => 1,
                'description' => 'Willingness to delay action until timing, resources, or positioning improve.',
            ],
            [
                'key'         => 'risk_tolerance',
                'name'        => 'Risk Tolerance',
                'category'    => 'risk',
                'sort_order'  => 2,
                'description' => 'Comfort with uncertain decisions that may produce high reward or high punishment.',
            ],
            [
                'key'         => 'defensive_discipline',
                'name'        => 'Defensive Discipline',
                'category'    => 'risk',
                'sort_order'  => 3,
                'description' => 'Tendency to preserve defensive resources and avoid unnecessary exposure.',
            ],
            [
                'key'         => 'kill_instinct',
                'name'        => 'Kill Instinct',
                'category'    => 'finishing',
                'sort_order'  => 4,
                'description' => 'Ability and willingness to identify and commit to genuine finishing opportunities.',
            ],
            [
                'key'         => 'control_orientation',
                'name'        => 'Control Orientation',
                'category'    => 'control',
                'sort_order'  => 5,
                'description' => 'Preference for limiting enemy options, managing tempo, and creating controlled game states.',
            ],
            [
                'key'         => 'pressure_orientation',
                'name'        => 'Pressure Orientation',
                'category'    => 'tempo',
                'sort_order'  => 6,
                'description' => 'Preference for maintaining pressure, uptime, and forcing enemy reactions.',
            ],
            [
                'key'         => 'structure_preference',
                'name'        => 'Structure Preference',
                'category'    => 'planning',
                'sort_order'  => 7,
                'description' => 'Preference for planned sequences, known setups, and coordinated patterns.',
            ],
            [
                'key'         => 'adaptability',
                'name'        => 'Adaptability',
                'category'    => 'learning',
                'sort_order'  => 8,
                'description' => 'Willingness to change decisions when new information appears.',
            ],
            [
                'key'         => 'creativity',
                'name'        => 'Creativity',
                'category'    => 'learning',
                'sort_order'  => 9,
                'description' => 'Tendency to use non-obvious, flexible, or unconventional solutions.',
            ],
            [
                'key'         => 'reactivity',
                'name'        => 'Reactivity',
                'category'    => 'timing',
                'sort_order'  => 10,
                'description' => 'Tendency to respond after events occur rather than anticipate them.',
            ],
            [
                'key'         => 'independence',
                'name'        => 'Independence',
                'category'    => 'teamplay',
                'sort_order'  => 11,
                'description' => 'Tendency to act from personal agency rather than waiting for team confirmation or structure.',
            ],
        ];

        foreach ($traits as $trait) {
            PlayerTrait::updateOrCreate(
                ['key' => $trait['key']],
                $trait
            );
        }
    }
}
