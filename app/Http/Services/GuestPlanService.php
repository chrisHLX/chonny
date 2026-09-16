<?php

namespace App\Http\Services;

use App\Enums\UserGuideType;
use App\Models\PageViewEvent;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideSection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plans a visitor makes before they have an account.
 *
 * WHY THIS EXISTS: every way into the builder used to go through sign-up first, so a visitor never
 * got to try the one thing the site is for. Now they can build a plan straight away, and signing
 * up (or signing in) keeps it.
 *
 * HOW IT WORKS. A guest plan is an ordinary user_guides row with user_id NULL and a random
 * guest_token. The same token sits in an encrypted cookie in the visitor's browser, and that cookie
 * is the only thing UserGuide::isEditableBy() accepts for a row with no owner. A cookie rather than
 * the session, because the session ends after two idle hours, which would lose somebody's plan
 * while they were away from the keyboard. The cookie lasts KEEP_DAYS and is refreshed on every
 * edit.
 *
 * WHAT A GUEST CANNOT DO: publish, share, sign with a character, duplicate, or appear anywhere
 * public. Those all go through Builder::authorOnly(), which is false for a guest plan, and a draft
 * is never readable by anyone who cannot edit it.
 *
 * CLEANUP happens when a visitor starts a plan, not on a schedule, because production runs no
 * scheduler. `guides:prune-guest-plans` does the same by hand.
 */
class GuestPlanService
{
    public const COOKIE = 'mc_guest_plans';

    public const KEEP_DAYS = 7;

    /** Plans one browser can hold at once. Starting another past this deletes the oldest. */
    public const MAX_PER_VISITOR = 3;

    /** The token in this browser's cookie, or null when it has none. */
    public function token(): ?string
    {
        $token = request()->cookie(self::COOKIE);

        return is_string($token) && strlen($token) === 64 ? $token : null;
    }

    /** Whether this guest plan belongs to the browser making the request. */
    public function owns(UserGuide $guide): bool
    {
        $token = $this->token();

        return $guide->user_id === null
            && $guide->guest_token !== null
            && $token !== null
            && hash_equals($guide->guest_token, $token);
    }

    /** Keep this browser's cookie alive while its plan is being worked on. */
    public function refreshCookie(): void
    {
        if ($token = $this->token()) {
            Cookie::queue(self::COOKIE, $token, self::KEEP_DAYS * 24 * 60);
        }
    }

    /** Start a guest plan for this browser, optionally with a comp already filled in. */
    public function start(UserGuideType $type, array $teamSpecIds = []): UserGuide
    {
        $this->pruneStale();

        $token = $this->token() ?? Str::random(64);
        Cookie::queue(self::COOKIE, $token, self::KEEP_DAYS * 24 * 60);

        UserGuide::whereNull('user_id')
            ->where('guest_token', $token)
            ->orderByDesc('updated_at')
            ->skip(self::MAX_PER_VISITOR - 1)
            ->take(100)
            ->get()
            ->each(fn (UserGuide $old) => $this->deleteWithBuilds($old));

        $guide = UserGuide::startDraft(null, $type, $teamSpecIds, $token);

        PageViewEvent::log('guide_try', slot: $type->value);

        return $guide;
    }

    /**
     * Move this browser's guest plans onto an account that has just signed up or signed in.
     * Returns the most recently edited one so the caller can open it, or null when there were none.
     */
    public function claim(User $user): ?UserGuide
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        $guides = UserGuide::whereNull('user_id')->where('guest_token', $token)->orderBy('updated_at')->get();

        Cookie::queue(Cookie::forget(self::COOKIE));

        if ($guides->isEmpty()) {
            return null;
        }

        DB::transaction(function () use ($guides, $user) {
            foreach ($guides as $guide) {
                $guide->user_id = $user->id;
                $guide->guest_token = null;
                $guide->last_edited_by_user_id = $user->id;
                // A guest slug is random so nobody can guess it; an owned guide gets a readable one.
                $guide->slug = $guide->uniqueSlugFrom(Str::slug($guide->title ?? 'guide') ?: 'guide');
                $guide->save();

                $sectionIds = $guide->sections()->pluck('id');

                // Everything a guest wrote was theirs, so credit it to the account that now owns it.
                UserGuideSection::whereIn('id', $sectionIds)->whereNull('created_by_user_id')->update(['created_by_user_id' => $user->id]);
                UserGuideSection::whereIn('id', $sectionIds)->whereNull('updated_by_user_id')->update(['updated_by_user_id' => $user->id]);
                UserGuideBlock::whereIn('user_guide_section_id', $sectionIds)->whereNull('added_by_user_id')->update(['added_by_user_id' => $user->id]);
            }
        });

        PageViewEvent::log('guide_try_claimed', slot: (string) $guides->count());

        return $guides->last()->fresh();
    }

    /** Delete guest plans nobody has touched in KEEP_DAYS. Returns how many were removed. */
    public function pruneStale(int $limit = 200): int
    {
        $stale = UserGuide::whereNull('user_id')
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS))
            ->limit($limit)
            ->get();

        $stale->each(fn (UserGuide $guide) => $this->deleteWithBuilds($guide));

        return $stale->count();
    }

    /**
     * A guide's comp slots can each own a talent build row, and deleting the guide does not delete
     * those (the foreign key points from the slot to the build). Guest plans are deleted
     * automatically, so without this they would leave orphaned builds behind.
     */
    private function deleteWithBuilds(UserGuide $guide): void
    {
        $buildIds = $guide->members()->whereNotNull('talent_build_id')->pluck('talent_build_id')
            ->merge($guide->enemies()->whereNotNull('talent_build_id')->pluck('talent_build_id'));

        DB::transaction(function () use ($guide, $buildIds) {
            $guide->delete();
            TalentBuild::whereIn('id', $buildIds)->where('is_default', false)->whereNull('user_id')->delete();
        });
    }
}
