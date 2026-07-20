<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\NextStepService;
use App\Http\Services\RoadmapService;
use App\Http\Services\SubjectContextService;
use App\Models\FunnelEvent;
use App\Models\Module;
use App\Models\PlayerTrait;
use App\Models\User;
use App\Models\UserModuleHistory;
use App\Models\UserProfileEvidence;
use App\Models\UserTraitEvidence;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    private function claimGuestQuizResults(User $user): void
    {
        $guestResults = session('guest_quiz_results', []);
        if (empty($guestResults)) return;

        foreach ($guestResults as $moduleId => $result) {
            try {
                // Diagnostic sessions carry trait_scores/diagnostic_profile instead of
                // score/question_results — they have no correct/incorrect signal to replay.
                if (array_key_exists('trait_scores', $result)) {
                    $module = Module::with('subject')->find($moduleId);

                    DB::transaction(function () use ($user, $moduleId, $result, $module) {
                        $user->modules()->syncWithoutDetaching([
                            $moduleId => [
                                'status'             => 'completed',
                                'last_activity_at'   => \Carbon\Carbon::parse($result['completed_at']),
                                'completed_at'       => \Carbon\Carbon::parse($result['completed_at']),
                                'diagnostic_profile' => json_encode($result['diagnostic_profile'] ?? null),
                            ],
                        ]);

                        $answeredAt = \Carbon\Carbon::parse($result['completed_at']);
                        $categoryId = $module?->subject?->category_id;
                        $subjectId  = $module?->subject_id;

                        // Write per-question trait/survey evidence captured during the guest session
                        foreach ($result['question_evidence'] ?? [] as $evidence) {
                            if (($evidence['type'] ?? null) === 'survey') {
                                UserProfileEvidence::updateOrCreate(
                                    [
                                        'user_id'     => $user->id,
                                        'question_id' => $evidence['question_id'],
                                    ],
                                    [
                                        'module_id'    => $moduleId,
                                        'category_id'  => $categoryId,
                                        'subject_id'   => $subjectId,
                                        'question_key' => $evidence['question_key'],
                                        'answer_text'  => $evidence['answer_text'],
                                        'answer_value' => $evidence['answer_value'] ?? null,
                                        'metadata'     => null,
                                        'answered_at'  => $answeredAt,
                                    ]
                                );

                                continue;
                            }

                            $trait = PlayerTrait::where('key', $evidence['trait_key'])->first();
                            if (!$trait) {
                                Log::warning("Guest claim: unknown trait key '{$evidence['trait_key']}' — skipping");
                                continue;
                            }

                            UserTraitEvidence::updateOrCreate(
                                [
                                    'user_id'     => $user->id,
                                    'question_id' => $evidence['question_id'],
                                    'trait_id'    => $trait->id,
                                ],
                                [
                                    'module_id'             => $moduleId,
                                    'selected_answer'       => $evidence['selected_answer'],
                                    'selected_option_index' => $evidence['selected_option_index'] ?? null,
                                    'points'                => $evidence['points'],
                                    'answered_at'           => $answeredAt,
                                ]
                            );
                        }

                        // Context declared during the diagnostic's declare-context step
                        // (DiagnosticQuizRunner::handleContextDeclared(), guest branch) — kept
                        // in-memory only until now since SubjectContextService::declare()
                        // requires a real user_id. Keyed dimension_id => option_id, same shape
                        // SubjectContextForm::save() produces for an auth user.
                        $service = app(SubjectContextService::class);
                        foreach ($result['declared_context'] ?? [] as $dimensionId => $optionId) {
                            try {
                                $service->declare($user->id, (int) $dimensionId, (int) $optionId);
                            } catch (\Throwable $e) {
                                Log::warning('Guest claim: failed to declare context', [
                                    'dimension_id' => $dimensionId,
                                    'option_id'    => $optionId,
                                    'error'        => $e->getMessage(),
                                ]);
                            }
                        }
                    });

                    // Funnel attribution join key: RegisteredUserController::store() never calls
                    // session()->regenerate() (unlike AuthenticatedSessionController's login flow),
                    // so session()->getId() here is still the same id GuestRoadmap logged
                    // profile_viewed/roadmap_clicked under during the guest visit. Logged after the
                    // transaction above commits, per FunnelEvent's own "never break the flow it's
                    // observing" contract.
                    FunnelEvent::log('signup_completed', session()->getId(), (int) $moduleId, $user->id);

                    // Carry the diagnostic's own subject/category into the session context so the
                    // post-signup dashboard shows this subject instead of silently falling back to
                    // Category::first()/Subject::first() (see the "Category/subject selection is
                    // remembered in session" invariant in CLAUDE.md).
                    if ($module?->subject_id && $module?->subject?->category_id) {
                        session([
                            'context.category_id' => $module->subject->category_id,
                            'context.subject_id'  => $module->subject_id,
                        ]);
                    }

                    // Only clear this module's guest data once its transfer has committed
                    session()->forget("guest_quiz_results.{$moduleId}");

                    // Same insight + first-task creation a logged-in diagnostic completion gets
                    // (DiagnosticQuizRunner::recordProfileInsight) — without this, a guest who
                    // signs up after the diagnostic never gets a live next-step, only the static
                    // next_practice_goal string baked into the profile JSON.
                    $insight = app(NextStepService::class)->recordInsightAndGenerateInitialStep(
                        $user->id,
                        $module,
                        $result['diagnostic_profile'] ?? []
                    );

                    // Same persisted learning-path stages a logged-in diagnostic completion gets
                    // (DiagnosticQuizRunner::recordProfileInsight) — without this, a guest's
                    // roadmap (shown pre-signup via GuestRoadmap) simply vanishes on registration
                    // instead of carrying over to the Progress page.
                    if ($module) {
                        app(RoadmapService::class)->persistStagesForUser(
                            $user->id,
                            $module,
                            $result['diagnostic_profile'] ?? [],
                            $result['survey_answers'] ?? [],
                            $insight?->id
                        );
                    }

                    continue;
                }

                $module = Module::with('subject')->find($moduleId);

                DB::transaction(function () use ($user, $moduleId, $result) {
                    $user->modules()->syncWithoutDetaching([
                        $moduleId => [
                            'status'           => 'completed',
                            'score'            => $result['score'],
                            'last_activity_at' => \Carbon\Carbon::parse($result['completed_at']),
                            'completed_at'     => \Carbon\Carbon::parse($result['completed_at']),
                        ],
                    ]);

                    foreach ($result['question_results'] as $questionId => $correct) {
                        $user->answeredQuestions()->syncWithoutDetaching([
                            $questionId => [
                                'attempts'            => 1,
                                'correct_count'       => $correct ? 1 : 0,
                                'last_answered_at'    => now(),
                                'last_time_spent'     => 0,
                                'total_time_spent'    => 0,
                                'last_answer'         => '',
                                'last_answer_correct' => $correct,
                                'consecutive_fails'   => $correct ? 0 : 1,
                            ],
                        ]);
                    }

                    UserModuleHistory::create([
                        'user_id'         => $user->id,
                        'module_id'       => $moduleId,
                        'attempt_number'  => 1,
                        'wrong_questions' => array_keys(array_filter($result['question_results'], fn($c) => !$c)),
                        'right_questions' => array_keys(array_filter($result['question_results'])),
                        'module_version'  => 'V1',
                        'status'          => 'completed',
                    ]);
                });

                if ($module?->subject_id && $module?->subject?->category_id) {
                    session([
                        'context.category_id' => $module->subject->category_id,
                        'context.subject_id'  => $module->subject_id,
                    ]);
                }

                session()->forget("guest_quiz_results.{$moduleId}");
            } catch (\Throwable $e) {
                Log::error("Guest quiz claim failed for module {$moduleId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        FunnelEvent::log('signup_started', session()->getId());

        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'g-recaptcha-response' => ['required', 'string', function ($attribute, $value, $fail) use ($request) {
                if (app()->environment('local')) {
                    return; // skip reCAPTCHA on localhost
                }

                $result = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret_key'),
                    'response' => $value,
                    'remoteip' => $request->ip(),
                ]);
                if (! $result->json('success') || $result->json('score', 0) < 0.5) {
                    $fail('reCAPTCHA verification failed. Please try again.');
                }
            }],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        $this->claimGuestQuizResults($user);

        return redirect(route('dashboard', absolute: false));
    }
}
