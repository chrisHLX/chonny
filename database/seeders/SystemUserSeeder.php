<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemUserSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate (not firstOrCreate) so re-running this seeder picks up a name change —
        // e.g. the 2026-07-20 rename from "MindCollector Engine" to "MindCollector" for the
        // public-facing "by MindCollector" byline shown on modules attributed to this account.
        User::updateOrCreate(
            ['email' => User::SYSTEM_ENGINE_EMAIL],
            [
                'name' => 'MindCollector',
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );
    }
}
