<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;
use App\Models\Counter;

class CountersSeeder extends Seeder
{
    public function run(): void
    {
        $jsonFiles = [
            database_path('data/zerg_units.json'),
            database_path('data/terran_units.json'),
            database_path('data/protoss_units.json'),
        ];

        foreach ($jsonFiles as $file) {
            $units = json_decode(file_get_contents($file), true);

            foreach ($units as $unitData) {
                $unit = Unit::where('name', $unitData['name'])->first();

                if (!$unit) {
                    $this->command->warn("⚠️ Unit '{$unitData['name']}' not found. Skipping counters.");
                    continue;
                }

                if (!empty($unitData['counters'])) {
                    foreach ($unitData['counters'] as $counter) {
                        $counterUnit = Unit::where('name', $counter['counter_unit'])->first();

                        if (!$counterUnit) {
                            $this->command->warn("⚠️ Counter unit '{$counter['counter_unit']}' not found. Skipping.");
                            continue;
                        }

                        Counter::create([
                            'unit_id' => $unit->id,
                            'counter_unit_id' => $counterUnit->id,
                            'effectiveness' => $counter['effectiveness'],
                            'notes' => $counter['notes'] ?? null,
                        ]);
                    }
                }
            }
        }

        $this->command->info('✅ Counters seeded successfully!');
    }
}
