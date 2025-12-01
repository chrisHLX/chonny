<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insert([
            [
                'name' => 'Games',
                'description' => 'Video games, esports, board games, competitive gaming, and entertainment-based interactive experiences.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Arts',
                'description' => 'Creative disciplines such as visual arts, music, literature, design, and all forms of artistic expression.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Science',
                'description' => 'Scientific fields including biology, physics, chemistry, medicine, mathematics, and empirical research disciplines.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Humanities',
                'description' => 'Philosophy, history, culture, ethics, languages, and other studies related to human society and thought.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Trades',
                'description' => 'Practical and vocational trades such as mechanics, construction, hydraulics, electrical work, and industrial skills.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Technology',
                'description' => 'Computing, programming, software development, AI, cybersecurity, networking, and modern technical fields.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
