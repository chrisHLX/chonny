<?php

namespace App\Jobs;

use App\Models\Module;
use App\Models\User;
use App\Http\Services\CardGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class GenerateCardJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels, Dispatchable;

    protected $userId;
    protected $moduleId;

    public function __construct(int $userId, int $moduleId)
    {
        $this->userId = $userId;
        $this->moduleId = $moduleId;
    }

    public function handle(CardGenerationService $service)
    {
        $user = User::find($this->userId);
        $module = Module::find($this->moduleId);

        if (! $user || ! $module) {
            return;
        }

        try {
            $service->generateFor($this->userId, $this->moduleId);
        } catch (\Throwable $e) {
            \Log::error('Card generation failed', [
                'user_id' => $this->userId,
                'module_id' => $this->moduleId,
                'error' => $e->getMessage(),
            ]);
        }
        
    }
}
