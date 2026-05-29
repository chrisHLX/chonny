<?php

namespace App\Livewire;

use App\Jobs\AnswerPromptJob;
use App\Models\Prompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ContentQa extends Component
{
    public string $promptableType;
    public int $promptableId;
    public string $newQuestion = '';

    private const ALLOWED_TYPES = [
        \App\Models\ModulePage::class,
        \App\Models\SubjectContent::class,
    ];

    public function mount(string $promptableType, int $promptableId): void
    {
        $this->promptableType = $promptableType;
        $this->promptableId = $promptableId;
    }

    public function submit(): void
    {
        if (!Auth::check()) {
            return;
        }

        $this->validate(['newQuestion' => 'required|string|min:5|max:1000']);

        if (!in_array($this->promptableType, self::ALLOWED_TYPES, true)) {
            return;
        }

        $model = ($this->promptableType)::find($this->promptableId);
        if (!$model || empty($model->content)) {
            return;
        }

        $prompt = Prompt::create([
            'user_id'         => Auth::id(),
            'promptable_type' => $this->promptableType,
            'promptable_id'   => $this->promptableId,
            'question'        => $this->newQuestion,
            'context_snapshot' => $model->content,
            'status'          => 'pending',
        ]);

        $this->newQuestion = '';

        AnswerPromptJob::dispatch($prompt->id);
    }

    public function getPromptsProperty(): Collection
    {
        if (!Auth::check()) {
            return collect();
        }

        return Prompt::where('promptable_type', $this->promptableType)
            ->where('promptable_id', $this->promptableId)
            ->where('user_id', Auth::id())
            ->latest()
            ->get();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.content-qa');
    }
}
