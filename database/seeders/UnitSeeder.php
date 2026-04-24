<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Unit;
use App\Models\UnitAttribute;
use App\Models\Ability;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $unitDataFiles = [
            'data/protoss_units.json',
            'data/terran_units.json',
            'data/zerg_units.json',
        ];

        $createdCount = 0;

        foreach ($unitDataFiles as $filePath) {
            $jsonPath = database_path($filePath);

            if (!File::exists($jsonPath)) {
                $this->command->error("❌ JSON file not found: {$jsonPath}");
                continue;
            }

            $unitsData = json_decode(File::get($jsonPath), true);

            foreach ($unitsData as $unitData) {
                $unit = Unit::firstOrCreate(
                    ['name' => $unitData['name'], 'race' => $unitData['race']],
                    ['type' => $unitData['type'], 'description' => $unitData['description']]
                );

                if ($unit->wasRecentlyCreated) {
                    $createdCount++;

                    if (!empty($unitData['attributes'])) {
                        foreach ($unitData['attributes'] as $attr) {
                            UnitAttribute::create([
                                'unit_id' => $unit->id,
                                'attribute_name' => $attr['attribute_name'],
                                'value' => $attr['value'],
                            ]);
                        }
                    }

                    if (!empty($unitData['abilities'])) {
                        foreach ($unitData['abilities'] as $ability) {
                            Ability::create([
                                'unit_id' => $unit->id,
                                'name' => $ability['name'],
                                'description' => $ability['description'],
                            ]);
                        }
                    }
                }
            }
        }

        $this->command->info("✅ Units seeded successfully! Created: {$createdCount}");
    }
}
