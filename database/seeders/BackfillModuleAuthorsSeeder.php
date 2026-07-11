<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Seeder;

class BackfillModuleAuthorsSeeder extends Seeder
{
    public function run(): void
    {
        $systemUserId = User::where('email', User::SYSTEM_ENGINE_EMAIL)->value('id');

        if (!$systemUserId) {
            $this->command->warn('System user not found — run SystemUserSeeder first. Skipping backfill.');
            return;
        }

        $updated = Module::whereNull('created_by')->update(['created_by' => $systemUserId]);

        $this->command->info("✅ Backfilled created_by on {$updated} module(s) with no author.");
    }
}
