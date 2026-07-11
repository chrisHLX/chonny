<?php

namespace Database\Seeders;

use App\Models\Axis;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

// Seeds Axes for every Category. Consolidated from the former GameAxesSeeder (Games only)
// and the orphaned CategoriesAndAxesSeeder (which also duplicated Category/Subject
// creation already handled by CategorySeeder/SubjectSeeder — that duplication was
// dropped here; only its axis data was kept). Must run after CategorySeeder.
class AxesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->axesByCategory() as $categoryName => $axes) {
            $category = Category::where('name', $categoryName)->first();

            if (! $category) {
                Log::warning("AxesSeeder: category '{$categoryName}' not found — skipping.");
                continue;
            }

            foreach ($axes as $axis) {
                Axis::updateOrCreate(
                    ['category_id' => $category->id, 'name' => $axis['name']],
                    ['description' => $axis['description']]
                );
            }
        }
    }

    private function axesByCategory(): array
    {
        return [
            'Games' => [
                [
                    'name'        => 'Resources',
                    'description' => 'Managing the acquisition, allocation, and efficient use of in-game resources such as currency, units, time, or production capacity. Includes optimizing income, avoiding waste, and hitting power spikes.',
                ],
                [
                    'name'        => 'Execution',
                    'description' => 'The player\'s ability to physically perform actions accurately and efficiently. Includes mechanics, timing, precision, multitasking, and input control under pressure.',
                ],
                [
                    'name'        => 'Information',
                    'description' => 'Gathering, interpreting, and acting on information about the game state. Includes scouting, vision, awareness, and understanding opponent behavior or hidden elements.',
                ],
                [
                    'name'        => 'Decision',
                    'description' => 'Making effective choices based on available information. Includes both long-term planning (strategy) and short-term actions (tactics), such as when to attack, defend, expand, or change approach.',
                ],
                [
                    'name'        => 'Control',
                    'description' => 'Influencing and maintaining control over areas, objectives, or flow of the game. Includes map control, positioning, pressure, and restricting the opponent\'s options.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Adjusting decisions and playstyle in response to changing conditions, new information, or opponent behavior. Includes reacting to unexpected situations, recovering from mistakes, and shifting strategies.',
                ],
            ],
            'Technology' => [
                [
                    'name'        => 'Logic',
                    'description' => 'Applying reasoning to break down problems, construct valid arguments, and identify flaws in code or system designs.',
                ],
                [
                    'name'        => 'Systems',
                    'description' => 'Understanding how components interact within larger architectures, including dependencies, data flow, and emergent behaviour.',
                ],
                [
                    'name'        => 'Implementation',
                    'description' => 'Writing, structuring, and shipping working code that correctly solves the problem at hand.',
                ],
                [
                    'name'        => 'Optimization',
                    'description' => 'Improving performance, efficiency, and resource usage through profiling, algorithmic thinking, and trade-off analysis.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Learning new tools, languages, and paradigms; adjusting approaches when requirements or constraints change.',
                ],
                [
                    'name'        => 'Communication',
                    'description' => 'Expressing technical ideas clearly through code, documentation, and collaboration with other developers.',
                ],
            ],
            'Science' => [
                [
                    'name'        => 'Knowledge',
                    'description' => 'Recalling and applying foundational facts about anatomy, physiology, pharmacology, and pathophysiology.',
                ],
                [
                    'name'        => 'Diagnosis',
                    'description' => 'Identifying the correct condition from signs, symptoms, and test results using clinical reasoning.',
                ],
                [
                    'name'        => 'Mechanisms',
                    'description' => 'Understanding the biological or physiological processes underlying disease, treatment, and drug action.',
                ],
                [
                    'name'        => 'Intervention',
                    'description' => 'Selecting and applying appropriate treatments, procedures, or management plans for a given condition.',
                ],
                [
                    'name'        => 'Analysis',
                    'description' => 'Interpreting data from investigations, imaging, or research literature to inform clinical decisions.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Adjusting clinical reasoning and management plans when initial approaches fail or new information emerges.',
                ],
            ],
            'Commerce' => [
                [
                    'name'        => 'Analysis',
                    'description' => 'Interpreting macroeconomic data, indicators, and trends to form a coherent view of the global economy.',
                ],
                [
                    'name'        => 'Decision',
                    'description' => 'Translating analytical insights into actionable investment or policy positions under uncertainty.',
                ],
                [
                    'name'        => 'Risk',
                    'description' => 'Identifying, quantifying, and managing downside scenarios, leverage, and exposure across markets.',
                ],
                [
                    'name'        => 'Systems',
                    'description' => 'Understanding the interconnected feedback loops between central banks, governments, currencies, and capital flows.',
                ],
                [
                    'name'        => 'Timing',
                    'description' => 'Assessing when macro conditions are likely to change and positioning for inflection points in markets or policy cycles.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Revising views and positions when new data, policy shifts, or unexpected events invalidate prior assumptions.',
                ],
            ],
            'Humanities' => [
                [
                    'name'        => 'Knowledge',
                    'description' => 'Recalling established facts about civilisations, events, key figures, dates, and chronological sequences.',
                ],
                [
                    'name'        => 'Interpretation',
                    'description' => 'Drawing meaning from primary and secondary sources, texts, inscriptions, and material artefacts.',
                ],
                [
                    'name'        => 'Causality',
                    'description' => 'Identifying and explaining the causes and consequences that link historical events and long-term developments.',
                ],
                [
                    'name'        => 'Comparison',
                    'description' => 'Analysing similarities and differences across civilisations, time periods, or competing historiographical traditions.',
                ],
                [
                    'name'        => 'Evidence',
                    'description' => 'Evaluating the reliability, bias, and limitations of historical sources and archaeological evidence.',
                ],
                [
                    'name'        => 'Perspective',
                    'description' => 'Recognising how modern assumptions, cultural context, and the historian\'s standpoint shape historical accounts.',
                ],
            ],
            'Arts' => [
                [
                    'name'        => 'Technique',
                    'description' => 'Controlling the physical skills required for performance, including precision, tone, timing, and consistency.',
                ],
                [
                    'name'        => 'Theory',
                    'description' => 'Understanding the structural principles of music — harmony, rhythm, form, notation, and counterpoint.',
                ],
                [
                    'name'        => 'Expression',
                    'description' => 'Communicating emotion, intent, and musical character through phrasing, dynamics, and interpretive choices.',
                ],
                [
                    'name'        => 'Creativity',
                    'description' => 'Generating original musical ideas, improvising, composing, and developing a personal artistic voice.',
                ],
                [
                    'name'        => 'Perception',
                    'description' => 'Listening analytically to identify harmonic, rhythmic, and structural elements within a piece.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Adjusting style, approach, or technique in response to different genres, ensembles, or performance contexts.',
                ],
            ],
            'Trades' => [
                [
                    'name'        => 'Materials',
                    'description' => 'Understanding the properties, classifications, and behaviour of metals, polymers, composites, and other industrial materials.',
                ],
                [
                    'name'        => 'Procedure',
                    'description' => 'Following and applying correct methods for fabrication, joining, forming, and finishing materials to specification.',
                ],
                [
                    'name'        => 'Safety',
                    'description' => 'Recognising hazards, applying safe work practices, and complying with relevant standards and regulations.',
                ],
                [
                    'name'        => 'Diagnostics',
                    'description' => 'Identifying material defects, failures, or degradation through inspection, testing, and root-cause analysis.',
                ],
                [
                    'name'        => 'Tools',
                    'description' => 'Selecting and correctly operating the appropriate hand tools, machines, and measuring instruments for a given task.',
                ],
                [
                    'name'        => 'Adaptation',
                    'description' => 'Adjusting techniques and material choices when specifications, conditions, or available resources change.',
                ],
            ],
        ];
    }
}
