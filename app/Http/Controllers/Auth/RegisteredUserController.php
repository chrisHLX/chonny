<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserModuleHistory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            } catch (\Throwable $e) {
                Log::error("Guest quiz claim failed for module {$moduleId}: " . $e->getMessage());
            }
        }

        session()->forget('guest_quiz_results');
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
