<?php

namespace Database\Seeders;

use App\Models\Axis;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class GameAxesSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('name', 'Games')->first();

        if (! $category) {
            Log::warning('GameAxesSeeder: Games category not found — skipping.');
            return;
        }

        $axes = [
            [
                'name' => 'Resources',
                'description' => 'Managing the acquisition, allocation, and efficient use of in-game resources such as currency, units, time, or production capacity. Includes optimizing income, avoiding waste, and hitting power spikes.',
            ],
            [
                'name' => 'Execution',
                'description' => 'The player\'s ability to physically perform actions accurately and efficiently. Includes mechanics, timing, precision, multitasking, and input control under pressure.',
            ],
            [
                'name' => 'Information',
                'description' => 'Gathering, interpreting, and acting on information about the game state. Includes scouting, vision, awareness, and understanding opponent behavior or hidden elements.',
            ],
            [
                'name' => 'Decision',
                'description' => 'Making effective choices based on available information. Includes both long-term planning (strategy) and short-term actions (tactics), such as when to attack, defend, expand, or change approach.',
            ],
            [
                'name' => 'Control',
                'description' => 'Influencing and maintaining control over areas, objectives, or flow of the game. Includes map control, positioning, pressure, and restricting the opponent\'s options.',
            ],
            [
                'name' => 'Adaptation',
                'description' => 'Adjusting decisions and playstyle in response to changing conditions, new information, or opponent behavior. Includes reacting to unexpected situations, recovering from mistakes, and shifting strategies.',
            ],
        ];

        foreach ($axes as $axis) {
            Axis::updateOrCreate(
                ['category_id' => $category->id, 'name' => $axis['name']],
                ['description' => $axis['description']]
            );
        }
    }
}
