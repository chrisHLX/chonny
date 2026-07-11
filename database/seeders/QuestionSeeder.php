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
        // Note: data/lolquestions.json is intentionally NOT loaded here — it was never
        // linked to any module (no module_questions.json entries reference a LoL module),
        // so it only ever produced orphaned, concept-less Question rows. LoL concept
        // coverage is fully provided by LolLaunchSeeder's own dedicated question set.
        $data = json_decode(file_get_contents(database_path('data/questions.json')), true);
        $data3 = json_decode(file_get_contents(database_path('data/medical_questions.json')), true);
        $data4 = json_decode(file_get_contents(database_path('data/wowquestions.json')), true);

        $data = array_merge($data, $data3, $data4);


        foreach ($data as $item) {
            // firstOrCreate on question text — matches the idempotent pattern used by
            // every other question-seeding path (WowPvpFundamentalsSeeder, LolLaunchSeeder,
            // the diagnostic seeders). Previously a plain create(), which duplicated rows
            // on any re-run of this seeder without a preceding migrate:fresh.
            Question::firstOrCreate(
                ['question' => $item['question']],
                [
                    'type'       => $item['type'],
                    'difficulty' => $item['difficulty'],
                    'skill_type' => $item['skill_type'] ?? 'recall',
                    'answer'     => $item['answer'],
                ]
            );
        }
    }
}
