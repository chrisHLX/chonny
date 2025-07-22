<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\User;
use App\Models\Module;  

class ModuleUserProg extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $json = File::get(database_path('data/user_module.json'));
        $data = json_decode($json, true);

        foreach ($data as $item) {
            $user = \App\Models\User::where('email', $item['user_email'])->first();
            $module = \App\Models\Module::where('name', $item['module_name'])->first();

            if ($user && $module) {
                $user->modules()->syncWithoutDetaching([
                    $module->id => [
                        'status' => $item['status'],
                        'score' => $item['score'],
                        'current_difficulty' => $item['current_difficulty'],
                        'last_activity_at' => now()
                    ]
                ]);
            }
        }
    }
}
