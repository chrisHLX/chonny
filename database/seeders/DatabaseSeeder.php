<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ========== USERS ==========
        $users = [
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Christian',
                'email' => 'christian@mindcollector.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(['email' => $user['email']], $user);
        }

        // ========== GROUP 1: CORE MASTER DATA ==========
        // Categories → Subjects → Proficiencies (each depends on the previous)
        $this->call([
            CategorySeeder::class,
            SubjectSeeder::class,
            ProficiencySeeder::class,
        ]);

        // ========== GROUP 1B: AXES ==========
        // Axes belong to categories, so must run after CategorySeeder
        $this->call([
            GameAxesSeeder::class,
        ]);

        // ========== GROUP 2: REFERENCE DATA ==========
        // Tags and concepts depend on subjects existing
        $this->call([
            TagSeeder::class,
            ConceptSeeder::class,
        ]);

        // ========== GROUP 3: PRIMARY CONTENT ==========
        // Modules depend on subjects & proficiencies
        // ModulePageSeeder must run after ModuleSeeder (needs module IDs)
        $this->call([
            ModuleSeeder::class,
            ModulePageSeeder::class,
            QuestionSeeder::class,
            UnitSeeder::class,
        ]);

        // ========== GROUP 4: RELATIONSHIPS ==========
        // Counters depend on units; user-module depends on users & modules
        // Module content depends on modules, questions, and concepts
        $this->call([
            CountersSeeder::class,
            UserModuleSeeder::class,
            ModuleContentSeeder::class,
        ]);

        // ========== GROUP 5: GAME LAUNCH SEEDERS ==========
        // Self-contained seeders: each creates modules, pages, and questions for their subject.
        // Concepts are NOT created here — they come from ConceptSeeder above.
        // Requires CategorySeeder, SubjectSeeder, ProficiencySeeder, GameAxesSeeder, ConceptSeeder.
        $this->call([
            WowPvpFundamentalsSeeder::class,
            LolLaunchSeeder::class,
        ]);
    }
}
