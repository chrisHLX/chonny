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
                'is_admin' => true,
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(['email' => $user['email']], $user);
        }

        // ========== SYSTEM USER ==========
        // Attribution account for AI-generated modules with no human author.
        // Must run before any module-creating seeder below so they can reference its ID.
        $this->call([
            SystemUserSeeder::class,
        ]);

        // ========== GROUP 1: CORE MASTER DATA ==========
        // Categories → Subjects → Proficiencies (each depends on the previous)
        $this->call([
            CategorySeeder::class,
            SubjectSeeder::class,
            ProficiencySeeder::class,
        ]);

        // ========== GROUP 1B: AXES + ARCHETYPES ==========
        // Both belong to categories, so must run after CategorySeeder.
        // AxesSeeder covers every category (consolidated from the former GameAxesSeeder,
        // Games-only, and the orphaned CategoriesAndAxesSeeder, which never ran).
        $this->call([
            AxesSeeder::class,
            ArchetypeSeeder::class,
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
        ]);

        // ========== GROUP 4: RELATIONSHIPS ==========
        // User-module depends on users & modules
        // Module content depends on modules, questions, and concepts
        $this->call([
            UserModuleSeeder::class,
            ModuleContentSeeder::class,
        ]);

        // ========== GROUP 5: GAME LAUNCH SEEDERS ==========
        // Self-contained seeders: each creates modules, pages, and questions for their subject.
        // Concepts are NOT created here — they come from ConceptSeeder above.
        // Requires CategorySeeder, SubjectSeeder, ProficiencySeeder, AxesSeeder, ConceptSeeder.
        $this->call([
            WowPvpFundamentalsSeeder::class,
            LolLaunchSeeder::class,
            PokerFundamentalsSeeder::class,
        ]);

        // ========== TRAITS ==========
        // Platform-wide player trait taxonomy for the diagnostic quiz system.
        // No dependencies — can run at any point after the traits table exists.
        $this->call([
            TraitsSeeder::class,
        ]);

        // ========== DIAGNOSTIC MODULES ==========
        // Playstyle/profile assessments for each game.
        // Requires SubjectSeeder, ProficiencySeeder, and TraitsSeeder to have run.
        $this->call([
            WoWDiagnosticModuleSeeder::class,
            LoLDiagnosticModuleSeeder::class,
            SC2DiagnosticModuleSeeder::class,
            PokerDiagnosticModuleSeeder::class,
        ]);

        // ========== BACKFILL ==========
        // Catches any module left with created_by = NULL (e.g. from a prior run of
        // these seeders before SystemUserSeeder existed). Safe to re-run.
        $this->call([
            BackfillModuleAuthorsSeeder::class,
        ]);
    }
}
