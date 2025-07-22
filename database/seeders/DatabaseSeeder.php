<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        User::factory()->createMany([
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => bcrypt('password'), // Use a secure password
            ],
            [
                'name' => 'Christian',
                'email' => 'christian@example.com',
                'password' => bcrypt('password'),
            ],
            [
                'name' => 'PIG',
                'email' => 'pig@proplayer.com',
                'password' => bcrypt('password'),
            ],
            [
                'name' => 'Serral',
                'email' => 'serral@proplayer.com',
                'password' => bcrypt('password'),
            ],
        ]);
        
        $this->call([
            ZergUnitSeeder::class,
            TerranUnitSeeder::class,
            ProtossUnitSeeder::class,
            CountersSeeder::class,
            ConceptsSeeder::class,
            QuestionSeeder::class,
            ModuleSeeder::class,
            UserModuleSeeder::class,
            ModuleUserProg::class,
            ModuleQuestionSeeder::class,
            // Add any other seeders here
        ]);
    
    }
}
