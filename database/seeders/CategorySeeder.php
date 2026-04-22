<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Games',
                'description' => 'Video games, esports, board games, competitive gaming, and entertainment-based interactive experiences.',
            ],
            [
                'name' => 'Arts',
                'description' => 'Creative disciplines such as visual arts, music, literature, design, and all forms of artistic expression.',
            ],
            [
                'name' => 'Science',
                'description' => 'Scientific fields including biology, physics, chemistry, medicine, mathematics, and empirical research disciplines.',
            ],
            [
                'name' => 'Humanities',
                'description' => 'Philosophy, history, culture, ethics, languages, and other studies related to human society and thought.',
            ],
            [
                'name' => 'Trades',
                'description' => 'Practical and vocational trades such as mechanics, construction, hydraulics, electrical work, and industrial skills.',
            ],
            [
                'name' => 'Technology',
                'description' => 'Computing, programming, software development, AI, cybersecurity, networking, and modern technical fields.',
            ],
            [
                'name' => 'Commerce',
                'description' => 'The study of trade, economic systems, financial markets, investment principles, and the exchange of value in society.',
            ],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                ['description' => $category['description']],
            );
        }
    }
}