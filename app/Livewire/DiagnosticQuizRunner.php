<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Module;
use App\Models\UserAxisMastery;
use App\Models\PlayerTrait;
use App\Models\UserConceptMastery;
use App\Models\UserTraitEvidence;
use App\Models\UserProfileEvidence;
use App\Http\Services\DiagnosticProfileService;
use App\Http\Services\NextStepService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DiagnosticQuizRunner extends Component
{
    public bool $guestMode = false;
    public bool $introShown = false;
    public string $moduleName = '';

    public $moduleId;
    public $questions;
    public $currentIndex = 0;
    public $answer = [];
    public $elapsed = 0;
    public $questionTimes = [];
    public $completed = false;
    public $quizFullyComplete = false;
    public $status;
    public $proficiency;
    public $difficulty = 'diagnostic';

    public $questionResults = [];
    public $userCredits = null;
    public $feedback;

    // Diagnostic-specific
    public array $traitScores = [];
    public array $surveyAnswers = [];   // keyed by question_key — context facts, not trait signals
    public array $guestEvidenceLog = [];
    public ?array $diagnosticProfile = null;
    public bool $retakingDiagnostic = false;
    public ?string $retakeStartedAt = null;

    public function mount($moduleId): void
    {
        $this->moduleId  = $moduleId;
        $this->guestMode = auth()->guest();
        $this->startQuizInternal();
    }

    public function startAssessment(): void
    {
        $this->introShown = true;
    }

    public function startQuizInternal(): void
    {
        if ($this->guestMode) {
            $module = Module::with(['questions', 'proficiencies'])->find($this->moduleId);
            if (!$module) return;

            $this->moduleName = $module->name ?? '';

            // Reload stored profile on page refresh — skipped while a retake is in progress
            $stored = session('guest_quiz_results.' . $this->moduleId);
            if ($stored && isset($stored['diagnostic_profile']) && !$this->retakingDiagnostic) {
                $this->diagnosticProfile  = $stored['diagnostic_profile'];
                $this->traitScores        = $stored['trait_scores'] ?? [];
                $this->surveyAnswers      = $stored['survey_answers'] ?? [];
                $this->quizFullyComplete  = true;
                $this->completed          = true;
                $this->status             = 'completed';
                $this->introShown         = true;
                $this->retakingDiagnostic = false;
                return;
            }

            $this->proficiency = $module->proficiencies()->first()->name ?? '—';
            $this->questions   = $this->loadQuestions($module);
            $this->initState();
            return;
        }

        $user              = auth()->user();
        $this->userCredits = $user->credits()->firstOrCreate([]);

        if ($this->userCredits->ai_credits <= 5) {
            $this->feedback = "You've reached your alpha credit limit. Submit feedback or contact Christian if you'd like more credits while testing.";
            return;
        }

        $module = $user->modules()->with('questions')->find($this->moduleId);
        if (!$module) return;

        $this->moduleName = $module->name ?? '';

        $retakeAt              = $module->pivot->retake_started_at;
        $this->retakeStartedAt = $retakeAt
            ? \Carbon\Carbon::parse($retakeAt)->toDateTimeString()
            : null;

        $this->proficiency = $module->proficiencies()->first()->name ?? '—';
        $this->status      = $module->pivot->status ?? 'in_progress';

        // Reload completed profile on page refresh without re-running AI
        if ($module->pivot->status === 'completed') {
            $this->completed         = true;
            $this->quizFullyComplete = true;
            $this->questions         = $this->questions ?? collect();
            $this->status            = 'completed';
            $this->introShown        = true;
            $storedProfile           = $module->pivot->diagnostic_profile;
            $this->diagnosticProfile = $storedProfile ? json_decode($storedProfile, true) : null;
            return;
        }

        $this->questions = $this->loadQuestions($module);
        $this->initState();
    }

    public function submit($params = []): void
    {
        if (!isset($this->questions[$this->currentIndex])) {
            return;
        }

        $question = $this->questions[$this->currentIndex];

        // Guard against submitting with no option selected — mirrors the client-side
        // `required` radio validation, but also covers a direct Livewire call that
        // bypasses the DOM (stale request, JS disabled, etc).
        if (is_array($this->answer) || trim((string) $this->answer) === '') {
            return;
        }

        $this->elapsed = isset($params['elapsed']) ? (int) $params['elapsed'] : 0;

        $selectedOption = null;

        // Accumulate trait signals — diagnostic questions have no correct/incorrect
        if ($question->type === 'diagnostic_mcq') {
            $options        = $question->answer['options'] ?? [];
            $selectedOption = collect($options)->first(function ($opt) {
                $text = is_array($opt) ? ($opt['text'] ?? null) : $opt;
                return $text === $this->answer;
            });
            if (is_array($selectedOption) && !empty($selectedOption['diagnostic_payload'])) {
                // Derive option index once for evidence logging
                $logOptionIndex = null;
                foreach ($options as $i => $opt) {
                    $optText = is_array($opt) ? ($opt['text'] ?? null) : $opt;
                    if ($optText === $this->answer) {
                        $logOptionIndex = $i;
                        break;
                    }
                }

                foreach ($selectedOption['diagnostic_payload']['traits'] ?? [] as $traitKey => $points) {
                    $this->traitScores[$traitKey] = ($this->traitScores[$traitKey] ?? 0) + $points;

                    if ($this->guestMode) {
                        $this->guestEvidenceLog[] = [
                            'question_id'           => $question->id,
                            'trait_key'             => $traitKey,
                            'points'                => $points,
                            'selected_answer'       => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                            'selected_option_index' => $logOptionIndex,
                        ];
                    }
                }
            }
        }

        // Survey questions collect self-reported context — not trait signals
        if ($question->type === 'survey_mcq') {
            $options        = $question->answer['options'] ?? [];
            $selectedOption = collect($options)->first(fn($opt) => ($opt['text'] ?? null) === $this->answer);

            if ($selectedOption) {
                $questionKey                       = $question->answer['question_key'] ?? "survey_{$question->id}";
                $this->surveyAnswers[$questionKey] = [
                    'text'  => $selectedOption['text'],
                    'value' => $selectedOption['value'] ?? null,
                ];

                if ($this->guestMode) {
                    $this->guestEvidenceLog[] = [
                        'type'         => 'survey',
                        'question_id'  => $question->id,
                        'question_key' => $questionKey,
                        'answer_text'  => $selectedOption['text'],
                        'answer_value' => $selectedOption['value'] ?? null,
                    ];
                } elseif (auth()->check()) {
                    $module     = Module::with('subject')->find($this->moduleId);
                    $categoryId = $module?->subject?->category_id;
                    $subjectId  = $module?->subject_id;

                    UserProfileEvidence::updateOrCreate(
                        ['user_id' => auth()->id(), 'question_id' => $question->id],
                        [
                            'module_id'    => $this->moduleId,
                            'category_id'  => $categoryId,
                            'subject_id'   => $subjectId,
                            'question_key' => $questionKey,
                            'answer_text'  => $selectedOption['text'],
                            'answer_value' => $selectedOption['value'] ?? null,
                            'metadata'     => null,
                            'answered_at'  => now(),
                        ]
                    );
                }
            }
        }

        $this->questionResults[$question->id] = true; // all answers accepted
        $this->questionTimes[]                = $this->elapsed;

        // Record the selected answer without a correctness or mastery signal
        if (auth()->check()) {
            $user     = auth()->user();
            $existing = $user->answeredQuestions()->where('question_id', $question->id)->first();

            if ($existing) {
                $user->answeredQuestions()->updateExistingPivot($question->id, [
                    'attempts'            => $existing->pivot->attempts + 1,
                    'correct_count'       => 0,
                    'last_answered_at'    => now(),
                    'last_time_spent'     => $this->elapsed,
                    'total_time_spent'    => $existing->pivot->total_time_spent + $this->elapsed,
                    'last_answer'         => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => null,
                    'consecutive_fails'   => 0,
                ]);
            } else {
                $user->answeredQuestions()->attach($question->id, [
                    'attempts'            => 1,
                    'correct_count'       => 0,
                    'last_answered_at'    => now(),
                    'last_time_spent'     => $this->elapsed,
                    'total_time_spent'    => $this->elapsed,
                    'last_answer'         => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => null,
                    'consecutive_fails'   => 0,
                ]);
            }

            // Persist per-question trait evidence for auth users
            if ($question->type === 'diagnostic_mcq'
                && is_array($selectedOption)
                && !empty($selectedOption['diagnostic_payload']['traits'])) {

                $options     = $question->answer['options'] ?? [];
                $optionIndex = null;
                foreach ($options as $i => $opt) {
                    $text = is_array($opt) ? ($opt['text'] ?? null) : $opt;
                    if ($text === $this->answer) {
                        $optionIndex = $i;
                        break;
                    }
                }

                foreach ($selectedOption['diagnostic_payload']['traits'] as $traitKey => $points) {
                    $trait = PlayerTrait::where('key', $traitKey)->first();
                    if (!$trait) {
                        Log::warning("DiagnosticQuizRunner: unknown trait key '{$traitKey}' on question {$question->id}");
                        continue;
                    }

                    UserTraitEvidence::updateOrCreate(
                        [
                            'user_id'     => $user->id,
                            'question_id' => $question->id,
                            'trait_id'    => $trait->id,
                        ],
                        [
                            'module_id'             => $this->moduleId,
                            'selected_answer'       => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                            'selected_option_index' => $optionIndex,
                            'points'                => $points,
                            'answered_at'           => now(),
                        ]
                    );
                }
            }
        }

        $this->nextQuestion();
    }

    public function nextQuestion(): void
    {
        $this->answer  = [];
        $this->elapsed = 0;
        $this->currentIndex++;

        if ($this->currentIndex < $this->questions->count()) {
            return;
        }

        $this->completeModule();
    }

    public function retake(): void
    {
        $this->completed         = false;
        $this->quizFullyComplete = false;
        $this->questionResults   = [];
        $this->traitScores       = [];
        $this->surveyAnswers     = [];
        $this->guestEvidenceLog  = [];
        $this->diagnosticProfile = null;
        $this->currentIndex      = 0;
        $this->questions         = collect();
        $this->feedback          = null;
        $this->introShown        = false;

        if ($this->guestMode) {
            $this->retakingDiagnostic = true;
            session()->forget('guest_quiz_results.' . $this->moduleId);
            $this->startQuizInternal();
            return;
        }

        $user = auth()->user();
        if (!$user) return;

        $user->modules()->updateExistingPivot($this->moduleId, [
            'retake_started_at'  => now(),
            'retake_count'       => DB::raw('retake_count + 1'),
            'status'             => 'in_progress',
            'completed_at'       => null,
            'diagnostic_profile' => null,
        ]);

        $this->startQuizInternal();
    }

    public function getTotalTimeProperty(): int
    {
        return array_sum($this->questionTimes);
    }

    private function loadQuestions($module): \Illuminate\Support\Collection
    {
        $questions = $module->questions()
            ->get()
            ->transform(function ($q) {
                $q         = clone $q;
                $q->answer = array_merge([], $q->answer);
                return $q;
            });

        $surveyQuestions     = $questions->where('type', 'survey_mcq')->shuffle()->values();
        $diagnosticQuestions = $questions->where('type', '!=', 'survey_mcq')->shuffle()->values();

        return $this->interleaveSurveyQuestions($surveyQuestions, $diagnosticQuestions);
    }

    /**
     * Spreads survey_mcq questions evenly across the quiz — first question is
     * always a survey question, and (when there's more than one) so is the
     * last, with diagnostic_mcq questions filling the gaps as evenly as possible.
     */
    private function interleaveSurveyQuestions($surveyQuestions, $diagnosticQuestions): \Illuminate\Support\Collection
    {
        $surveyCount = $surveyQuestions->count();
        $total       = $surveyCount + $diagnosticQuestions->count();

        if ($surveyCount === 0 || $total === 0) {
            return $diagnosticQuestions;
        }

        $surveyPositions = [];
        $lastPosition    = -1;

        for ($i = 0; $i < $surveyCount; $i++) {
            $position = $surveyCount > 1
                ? (int) floor($i * ($total - 1) / ($surveyCount - 1))
                : 0;
            $position = max($position, $lastPosition + 1);
            $position = min($position, $total - 1);

            $surveyPositions[] = $position;
            $lastPosition      = $position;
        }

        $surveyPositions  = array_flip($surveyPositions);
        $ordered          = collect();
        $diagnosticIndex  = 0;

        for ($position = 0; $position < $total; $position++) {
            if (isset($surveyPositions[$position]) && $surveyQuestions->isNotEmpty()) {
                $ordered->push($surveyQuestions->shift());
            } elseif ($diagnosticIndex < $diagnosticQuestions->count()) {
                $ordered->push($diagnosticQuestions[$diagnosticIndex]);
                $diagnosticIndex++;
            }
        }

        return $ordered->values();
    }

    private function initState(): void
    {
        $this->completed       = false;
        $this->currentIndex    = 0;
        $this->questionResults = [];
        $this->questionTimes   = [];
        $this->elapsed         = 0;
        $this->status          = 'in_progress';
    }

    private function completeModule(): void
    {
        $this->completed         = true;
        $this->quizFullyComplete = true;
        $this->status            = 'completed';
        $this->questions         = $this->questions ?? collect();

        $userId = $this->guestMode ? null : auth()->id();

        // Guard: don't re-run AI if a profile was already committed for this retake attempt
        if (!$this->guestMode && $userId) {
            $existingPivot = auth()->user()->modules()->find($this->moduleId)?->pivot;
            if ($existingPivot
                && $existingPivot->diagnostic_profile
                && $this->retakeStartedAt
                && $existingPivot->completed_at >= $this->retakeStartedAt) {
                $this->diagnosticProfile = json_decode($existingPivot->diagnostic_profile, true);
                return;
            }
        }

        $axisScores    = [];
        $conceptScores = [];

        if (!$this->guestMode && $userId) {
            $axisScores = UserAxisMastery::where('user_id', $userId)
                ->with('axis')
                ->get()
                ->mapWithKeys(fn($m) => [$m->axis->name ?? "axis_{$m->axis_id}" => $m->mastery_percentage])
                ->toArray();

            $conceptScores = UserConceptMastery::where('user_id', $userId)
                ->with('concept')
                ->orderBy('mastery_percentage')
                ->take(5)
                ->get()
                ->mapWithKeys(fn($m) => [$m->concept->name ?? "concept_{$m->concept_id}" => $m->mastery_percentage])
                ->toArray();
        }

        try {
            $moduleModel = Module::with(['subject.category'])->find($this->moduleId);
            $this->diagnosticProfile = app(DiagnosticProfileService::class)
                ->generateProfile($this->traitScores, $axisScores, $conceptScores, $userId ?? 0, $this->guestMode, $this->surveyAnswers, $moduleModel);
        } catch (\Throwable $e) {
            Log::error('Diagnostic profile generation failed', ['error' => $e->getMessage()]);
            $this->diagnosticProfile = [
                'player_type'            => 'Assessment Complete',
                'narrative'              => 'Your answers have been recorded, but we were unable to generate a full profile right now.',
                'top_traits'             => array_slice(array_keys($this->traitScores), 0, 3),
                'growth_area'            => '',
                'next_module_suggestion' => '',
            ];
        }

        if ($this->guestMode) {
            session(['guest_quiz_results.' . $this->moduleId => [
                'module_id'          => $this->moduleId,
                'trait_scores'       => $this->traitScores,
                'survey_answers'     => $this->surveyAnswers,
                'question_evidence'  => $this->guestEvidenceLog,
                'diagnostic_profile' => $this->diagnosticProfile,
                'completed_at'       => now()->toIso8601String(),
            ]]);
        } elseif ($userId) {
            auth()->user()->modules()->syncWithoutDetaching([
                $this->moduleId => [
                    'status'             => 'completed',
                    'last_activity_at'   => now(),
                    'completed_at'       => now(),
                    'diagnostic_profile' => json_encode($this->diagnosticProfile),
                ],
            ]);

            $this->recordProfileInsight($userId, $moduleModel ?? Module::find($this->moduleId));
        }
    }

    /**
     * Additive to the pivot JSON write above — records the same profile into the
     * queryable user_profile_insights model and generates the first next-step task.
     * Delegates to NextStepService so the guest-claim path (RegisteredUserController)
     * can produce the exact same insight/next-step records for a guest who signs up.
     */
    private function recordProfileInsight(int $userId, ?Module $moduleModel): void
    {
        app(NextStepService::class)->recordInsightAndGenerateInitialStep($userId, $moduleModel, $this->diagnosticProfile);
    }

    public function render()
    {
        return view('livewire.diagnostic-quiz-runner');
    }
}
