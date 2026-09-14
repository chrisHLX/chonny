<?php

namespace App\Providers;

use App\Listeners\SendNewUserNotification;
use App\Models\User;
use App\Models\UserGuide;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Registered::class, SendNewUserNotification::class);

        Gate::define('admin', fn (User $user) => $user->is_admin);

        // Signup, password reset and password change all validate against Password::defaults().
        //
        // NO BREACH CHECK (->uncompromised()), deliberately. It was on for one day (2026-09-12)
        // and turned away a real person on every password he tried — each one genuinely appeared
        // in Have I Been Pwned (checked: the rule itself was working, a random strong password
        // passed on production). Most people reuse a few passwords, so on a site where an account
        // holds guides and characters rather than money, the check cost more signups than it
        // protected. Google / Battle.net sign-in is now the low-friction path instead.
        Password::defaults(fn () => Password::min(8));

        // {guide} in /guides/{guide}/edit and /g/{username}/{guide} is a slug, and guide slugs are
        // unique PER AUTHOR, not globally (see UserGuide's slug generation). A plain slug lookup —
        // what implicit binding did — can therefore find somebody else's guide. Found by the launch
        // round trip, 2026-09-14: production already held one player's "untitled-class-guide", so
        // every NEW player's first class guide opened that player's draft and got a 403; and
        // /g/bob/rmp-opener 404'd whenever another author also had an "rmp-opener".
        //   with {username}: that author's guide with that slug, nothing else.
        //   edit:            the viewer's own guide first, then one they may edit as a friend or
        //                    guildmate, then any match (so Builder::mount still answers 403).
        // Registered here, not in routes/web.php, so a future `route:cache` cannot drop it.
        // Known limit: a collaborator who also has their own guide with the same slug opens theirs.
        Route::bind('guide', function (string $value, \Illuminate\Routing\Route $route) {
            if ($username = $route->parameter('username')) {
                $author = User::where('username', $username)->first();

                return $author?->guides()->where('slug', $value)->first() ?? abort(404);
            }

            $candidates = UserGuide::where('slug', $value)->orderBy('id')->get();
            $viewer = auth()->user();

            return $candidates->firstWhere('user_id', $viewer?->id)
                ?? $candidates->first(fn (UserGuide $g) => $g->isEditableBy($viewer))
                ?? $candidates->first()
                ?? abort(404);
        });

        View::composer('*', function ($view) {
            $credits = Auth::check() ? Auth::user()->credits : null;

            $view->with([
                'nav_ai_credits' => $credits->ai_credits ?? 0,
                'nav_learned_credits' => $credits->learned_credits ?? 0,
            ]);
        });
    }
}
