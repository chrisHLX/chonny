<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {


        $users = [
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Christian',
                'email' => 'christian@example.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'PIG',
                'email' => 'pig@proplayer.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Serral',
                'email' => 'serral@proplayer.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }

        
        $this->call([
            CategorySeeder::class,
            SubjectSeeder::class,
            ProficiencySeeder::class,
            ConceptsSeeder::class,
            ModuleSeeder::class,
            UserModuleSeeder::class,
            ModuleUserProg::class,
            QuestionSeeder::class,
            ModuleQuestionSeeder::class,
            LolSeeder::class,
            MedicalSeeder::class,
            QuestionConceptSeeder::class,
            
            // Add any other seeders here
        ]);
    
    }
}
