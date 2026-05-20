<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Http\Services\AiService;
use App\Models\Module;
use App\Models\Tag;

use App\Models\Pipeline;

class TagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AiService $aiService): void
    {
        $modules = Module::all();

        foreach ($modules as $module) {

        
            $pipeline = Pipeline::create([
                'user_id' => 2,
                'module_id' => $module->id,
                'type' => 'attach tags',
                'status' => 'running',
                'started_at' => now(),
            ]);

            try {

                $generatedTags = $aiService->generateTags($module->id);

                $tagIds = [];

                
                foreach ($generatedTags as $tagData) {

                    $tag = Tag::firstOrCreate([
                        'subject_id' => $module->subject_id,
                        'name' => $tagData['name'],
                        'type' => $tagData['type'],
                    ]);

                    $tagIds[] = $tag->id;
                }

                $module->tags()->syncWithoutDetaching($tagIds);

                $pipeline->update([
                    'status' => 'completed',
                ]);

            } catch (\Exception $e) {

                $pipeline->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);

                Log::error('TagJob failed', [
                    'module_id' => $module->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}