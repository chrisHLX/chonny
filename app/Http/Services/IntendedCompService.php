<?php

namespace App\Http\Services;

use App\Enums\UserGuideType;
use App\Models\User;
use App\Models\UserGuide;

/**
 * Carries a comp a guest picked on /wow-comps across the sign-up (or sign-in) they have to do
 * before they can write a plan for it.
 *
 * WHY THIS EXISTS. /wow-comps is the most-visited page on the site by an order of magnitude, and
 * the only route from it into authoring is "write a plan for this comp". Asking a guest to sign up
 * first and then rebuild the comp they had just assembled loses most of them at exactly the moment
 * they were most interested; remembering it costs one session key.
 *
 * WHY A SERVICE rather than a method on WowComps, which is the only writer: the READERS are four
 * different auth entry points (email register, email login, Google, Battle.net), and a guest who
 * clicks the button may legitimately arrive at any of them — including login, when they turn out to
 * already have an account. Four copies of "pull the key, validate it, build the guide" is how those
 * paths drift apart, which this codebase has had to repair more than once.
 *
 * The session key is CONSUMED on the first read whether or not it produced a guide, so a later
 * unrelated sign-in on the same browser can never inherit a stale comp.
 *
 * This deliberately holds spec ids only — never a whole draft. It is a bookmark for one click, not
 * a guest authoring session; a guest who wants to build without an account is a separate, larger
 * feature.
 */
class IntendedCompService
{
    private const KEY = 'intended_comp';

    /** Remember the comp this guest wants to write about. Ids are validated on the way back out. */
    public function remember(array $specIds): void
    {
        session([self::KEY => array_values(array_map('intval', array_filter($specIds)))]);
    }

    public function has(): bool
    {
        return filled(session(self::KEY));
    }

    /**
     * Build the remembered comp as this user's new guide, or null when there is nothing remembered.
     *
     * Returns null rather than an empty guide when none of the remembered ids resolve to real specs
     * (a stale session across a patch that renumbered specs, a tampered value): dropping the user
     * into a blank builder they did not ask for is worse than sending them to their dashboard.
     */
    public function resume(User $user): ?UserGuide
    {
        $specIds = session()->pull(self::KEY);

        if (! is_array($specIds) || $specIds === []) {
            return null;
        }

        $guide = UserGuide::startDraft($user, UserGuideType::Comp, array_map('intval', $specIds));

        return $guide->members()->exists() ? $guide : null;
    }

    /** Where to send a user who has just authenticated: their remembered comp, or null for the usual place. */
    public function redirectAfterAuth(User $user): ?\Illuminate\Http\RedirectResponse
    {
        $guide = $this->resume($user);

        return $guide ? redirect()->route('guides.edit', ['guide' => $guide->slug]) : null;
    }
}
