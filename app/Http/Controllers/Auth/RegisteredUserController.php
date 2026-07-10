<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\NextStepService;
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
                    });

                    // Only clear this module's guest data once its transfer has committed
                    session()->forget("guest_quiz_results.{$moduleId}");

                    // Same insight + first-task creation a logged-in diagnostic completion gets
                    // (DiagnosticQuizRunner::recordProfileInsight) — without this, a guest who
                    // signs up after the diagnostic never gets a live next-step, only the static
                    // next_practice_goal string baked into the profile JSON.
                    app(NextStepService::class)->recordInsightAndGenerateInitialStep(
                        $user->id,
                        $module,
                        $result['diagnostic_profile'] ?? []
                    );

                    continue;
                }

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
