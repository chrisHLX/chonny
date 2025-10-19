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
        $moreData = json_decode(file_get_contents(database_path('data/lolquestions.json')), true);

        $data = array_merge($data, $moreData);


        foreach ($data as $item) {
            $question = Question::create([
                'question'   => $item['question'],
                'type'       => $item['type'],
                'difficulty' => $item['difficulty'],
                'answer'     => $item['answer'],
            ]);

            

            
        }
    }
}
