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
        User::firstOrCreate(
            ['email' => User::SYSTEM_ENGINE_EMAIL],
            [
                'name' => 'MindCollector Engine',
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );
    }
}
