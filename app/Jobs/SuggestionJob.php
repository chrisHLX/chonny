<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Services\AiService;
use App\Http\Services\UserModuleService;
use App\Http\Services\SuggestionsService;
use App\Models\Module;
use App\Models\User;

class SuggestionJob implements ShouldQueue // What is should que and what is implements
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    protected int $moduleID;
    protected int $userID;

    public function __construct(int $moduleID, int $userID)
    {
        $this->moduleID = $moduleID;
        $this->userID = $userID;
    } 

    public function handle(AiService $aiService, UserModuleService $userModuleService, SuggestionsService $suggestionsService) 
    {
        //grab the models/collection instance
        $user = User::where('id', $this->userID)->first();
        $module = Module::where('id', $this->moduleID)->first();
        try {
            $response = $userModuleService->nextModuleResponse($user, $module);
        } catch (\Throwable $e) {
            \Log::error("SuggestionJob failed inside nextModuleResponse(): ".$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // keep queue behaviour consistent
        }
    }

   
  
    
    
}