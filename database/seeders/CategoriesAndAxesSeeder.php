<?php

namespace Database\Seeders;

use App\Models\Axis;
use App\Models\Category;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class CategoriesAndAxesSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'category' => [
                    'name'        => 'Technology',
                    'description' => 'Computing, programming, software development, AI, cybersecurity, networking, and modern technical fields.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Programming',
                        'description' => 'Learn coding languages, software development, and computer science fundamentals.',
                    ],
                ],
                'axes' => [
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
            ],
            [
                'category' => [
                    'name'        => 'Science',
                    'description' => 'Scientific fields including biology, physics, chemistry, medicine, mathematics, and empirical research disciplines.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Medicine',
                        'description' => 'Study medicine and the structure of the human body and its systems.',
                    ],
                ],
                'axes' => [
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
            ],
            [
                'category' => [
                    'name'        => 'Commerce',
                    'description' => 'The study of trade, economic systems, financial markets, investment principles, and the exchange of value in society.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Global Macro',
                        'description' => 'Analyze how global events, interest rates, and systemic feedback loops (Reflexivity) drive market prices and national economies.',
                    ],
                ],
                'axes' => [
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
            ],
            [
                'category' => [
                    'name'        => 'Humanities',
                    'description' => 'Philosophy, history, culture, ethics, languages, and other studies related to human society and thought.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Ancient History',
                        'description' => 'Study past ancient events and their impact on the present.',
                    ],
                ],
                'axes' => [
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
            ],
            [
                'category' => [
                    'name'        => 'Arts',
                    'description' => 'Creative disciplines such as visual arts, music, literature, design, and all forms of artistic expression.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Music',
                        'description' => 'Study music theory, composition, and performance across genres and styles.',
                    ],
                ],
                'axes' => [
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
            ],
            [
                'category' => [
                    'name'        => 'Trades',
                    'description' => 'Practical and vocational trades such as mechanics, construction, hydraulics, electrical work, and industrial skills.',
                ],
                'subjects' => [
                    [
                        'name'        => 'Industrial Materials',
                        'description' => 'Learn the principles and practices of industrial materials, including metallurgy, composites, and manufacturing processes.',
                    ],
                ],
                'axes' => [
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
            ],
        ];

        foreach ($data as $entry) {
            $category = Category::updateOrCreate(
                ['name' => $entry['category']['name']],
                ['description' => $entry['category']['description']]
            );

            foreach ($entry['subjects'] as $subjectData) {
                Subject::updateOrCreate(
                    ['name' => $subjectData['name']],
                    ['description' => $subjectData['description'], 'category_id' => $category->id]
                );
            }

            foreach ($entry['axes'] as $axisData) {
                Axis::updateOrCreate(
                    ['category_id' => $category->id, 'name' => $axisData['name']],
                    ['description' => $axisData['description']]
                );
            }
        }
    }
}
