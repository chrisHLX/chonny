<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Unit;
use App\Models\UnitAttribute;
use App\Models\Ability;
use App\Models\Counter;

class TerranUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = database_path('data/terran_units.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(File::get($jsonPath), true);

        foreach ($data as $unitData) {
            // Create the Unit
            $unit = Unit::FirstOrCreate([
                'name' => $unitData['name'],
                'race' => $unitData['race'],
                'type' => $unitData['type'],
                'description' => $unitData['description'],
            ]);

            // Create Attributes
            if (!empty($unitData['attributes'])) {
                foreach ($unitData['attributes'] as $attr) {
                    UnitAttribute::create([
                        'unit_id' => $unit->id,
                        'attribute_name' => $attr['attribute_name'],
                        'value' => $attr['value'],
                    ]);
                }
            }

            // Create Abilities
            if (!empty($unitData['abilities'])) {
                foreach ($unitData['abilities'] as $ability) {
                    Ability::create([
                        'unit_id' => $unit->id,
                        'name' => $ability['name'],
                        'description' => $ability['description'],
                    ]);
                }
            }

        $this->command->info('✅ Terran units seeded successfully!');
    }
        }
    }               
