<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Services\GuestResultsClaimService;
use App\Models\FunnelEvent;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /** Public so Google / Battle.net sign-up record the same terms version — see SocialSignupService. */
    public const TOS_VERSION = '1.0';

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
            'terms' => ['accepted'],
            'g-recaptcha-response' => ['required', 'string', new Recaptcha('register')],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tos_accepted_at' => now(),
            'tos_version' => self::TOS_VERSION,
        ]);

        event(new Registered($user));

        Auth::login($user);

        app(GuestResultsClaimService::class)->claim($user);

        return redirect(route('dashboard', absolute: false));
    }
}
