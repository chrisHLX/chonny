<?php

namespace App\Listeners;

use App\Http\Services\FriendshipService;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

/**
 * Makes every new account friends with the site's welcome account (config app.welcome_friend_email).
 *
 * Every sign-up path fires Registered — the email form and SocialSignupService (Google, Battle.net)
 * — so this covers all three. Friendship grants edit access to that account's guides with
 * friends_can_edit on; keep that in mind before switching it on for one.
 */
class AddWelcomeFriend
{
    public function __construct(private FriendshipService $friendships) {}

    public function handle(Registered $event): void
    {
        $email = config('app.welcome_friend_email');

        if (! $email || ! $event->user instanceof User) {
            return;
        }

        try {
            $welcome = User::where('email', $email)->first();

            if ($welcome) {
                $this->friendships->befriend($welcome, $event->user);
            }
        } catch (\Throwable $e) {
            // A failed welcome friendship must never fail a sign-up.
            Log::warning('Welcome friend not added: '.$e->getMessage(), ['user_id' => $event->user->id]);
        }
    }
}
