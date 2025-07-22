<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Unit;
use App\Models\Concept;

class QuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $data = json_decode(file_get_contents(database_path('data/questions.json')), true);

        foreach ($data as $item) {
            $question = Question::create([
                'question'   => $item['question'],
                'type'       => $item['type'],
                'difficulty' => $item['difficulty'],
                'answer'     => $item['answer'],
            ]);

            // Attach related units
            if (!empty($item['units'])) {
                $unitIds = Unit::whereIn('name', $item['units'])->pluck('id');
                $question->units()->sync($unitIds);
            }

            // Attach related concepts
            if (!empty($item['concepts'])) {
                $conceptIds = Concept::whereIn('name', $item['concepts'])->pluck('id');
                $question->concepts()->sync($conceptIds);
            }
        }
    }
}
