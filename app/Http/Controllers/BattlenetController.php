<?php

namespace App\Http\Controllers;

use App\Http\Services\BattlenetAccountTakenException;
use App\Http\Services\BattlenetApiException;
use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\BattlenetClient;
use App\Http\Services\SocialSignupService;
use App\Jobs\SyncBattlenetCharacter;
use App\Models\BattlenetAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Battle.net: linking it to a signed-in account, and signing in / signing up with it.
 *
 * One OAuth round trip serves all three, because Blizzard only knows one registered callback URL:
 * - SIGNED IN  -> link (or re-link) this Battle.net account to the current MindCollector account.
 * - GUEST whose Battle.net account is already linked -> sign in as that account.
 * - GUEST who is new -> one more step: Battle.net shares no email address, so /auth/battlenet/finish
 *   asks for one, then creates the account with the characters already attached.
 *
 * The OAuth `state` is random, session-held and pulled (read once) — a callback cannot be completed
 * on someone else's session or replayed. The user token is never stored: everything the finish step
 * needs (the Battle.net id, battletag and character list) is read in the callback and parked in the
 * session for PENDING_MINUTES.
 *
 * A new account is NEVER merged into an existing one by a typed email. Nothing proves the person
 * finishing sign-up owns that address, so an email already in use is refused with directions to log
 * in and link from there.
 */
class BattlenetController extends Controller
{
    private const STATE_KEY = 'battlenet_oauth_state';

    private const PENDING_KEY = 'battlenet_pending_signup';

    private const PENDING_MINUTES = 15;

    public function __construct(
        private BattlenetClient $client,
        private BattlenetCharacterSyncService $sync,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            return $this->failure($request, 'Battle.net sign-in is not configured on this server.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->client->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);

        // The player pressed Cancel on Blizzard's consent screen.
        if ($request->filled('error')) {
            return $this->failure($request, 'Battle.net sign-in was cancelled.');
        }

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state', ''))
            || ! $request->filled('code')) {
            return $this->failure($request, 'That sign-in link had expired or was not started here. Please try again.');
        }

        try {
            $token = $this->client->exchangeCode((string) $request->query('code'));

            return $request->user()
                ? $this->linkToCurrentUser($request->user(), $token)
                : $this->signInOrStartSignup($request, $token);
        } catch (BattlenetAccountTakenException $e) {
            return $this->failure($request, $e->getMessage());
        } catch (BattlenetApiException $e) {
            // Blizzard's own refusal (a redirect URL not registered on the client, say) — worth
            // showing verbatim, it is usually the whole diagnosis.
            report($e);

            return $this->failure($request, 'Battle.net sign-in failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->failure($request, 'Battle.net sign-in failed on our side. Please try again.');
        }
    }

    /** The one-field "what's your email?" step for a brand-new Battle.net sign-up. */
    public function finishForm(Request $request): View|RedirectResponse
    {
        $pending = $this->pending($request);

        if (! $pending) {
            return redirect()->route('login')->withErrors(['email' => 'Your Battle.net sign-up expired. Please start again.']);
        }

        return view('auth.battlenet-finish', [
            'battletag' => $pending['battletag'],
            'characterCount' => count($pending['characters']),
        ]);
    }

    public function finishStore(Request $request, SocialSignupService $signup): RedirectResponse
    {
        $pending = $this->pending($request);

        if (! $pending) {
            return redirect()->route('login')->withErrors(['email' => 'Your Battle.net sign-up expired. Please start again.']);
        }

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'terms' => ['accepted'],
        ], [
            'email.unique' => 'An account already uses this email. Log in to it, then link Battle.net from My Characters.',
        ]);

        // Someone may have linked this Battle.net account in the minutes since the callback.
        if (BattlenetAccount::where('battlenet_id', $pending['id'])->exists()) {
            $request->session()->forget(self::PENDING_KEY);

            return redirect()->route('login')->withErrors(['email' => 'That Battle.net account is already linked to a MindCollector account. Sign in with Battle.net again.']);
        }

        $user = $signup->create(explode('#', $pending['battletag'])[0], $request->input('email'), false);

        $account = $this->sync->linkWithCharacters(
            $user,
            ['id' => $pending['id'], 'battletag' => $pending['battletag']],
            $pending['characters'],
        );

        $request->session()->forget(self::PENDING_KEY);
        $count = $this->queueDetailSyncs($account);

        return redirect()->route('characters.index')->with('battlenet_status',
            "Welcome to MindCollector, {$user->name}. We're fetching ratings, gear and talents for {$count} "
            .Str::plural('character', $count).' now.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Cascades to its characters; any guide attributed to one keeps the guide and loses the
        // attribution (user_guides.battlenet_character_id is nullOnDelete).
        $request->user()->battlenetAccount?->delete();

        return redirect()->route('characters.index')
            ->with('battlenet_status', 'Battle.net unlinked. Your characters were removed from MindCollector.');
    }

    // ------------------------------------------------------------------ the three outcomes

    private function linkToCurrentUser(User $user, string $token): RedirectResponse
    {
        $account = $this->sync->linkAccount($user, $token);
        $count = $this->queueDetailSyncs($account);

        return redirect()->route('characters.index')->with('battlenet_status',
            "Linked {$account->battletag}. Fetching ratings, gear and talents for {$count} "
            .Str::plural('character', $count).' — this takes a moment.');
    }

    private function signInOrStartSignup(Request $request, string $token): RedirectResponse
    {
        $info = $this->client->userInfo($token);
        $account = BattlenetAccount::where('battlenet_id', $info['id'])->with('user')->first();

        // Returning player: sign in, and refresh the character list while we hold a token that
        // can read it — a sign-in is the one moment that happens without asking.
        if ($account?->user) {
            Auth::login($account->user, remember: true);

            $count = $this->queueDetailSyncs($this->sync->linkAccount($account->user, $token, $info));

            return redirect()->intended(route('characters.index'))
                ->with('battlenet_status', "Signed in as {$info['battletag']}. Refreshing {$count} "
                    .Str::plural('character', $count).'.');
        }

        $request->session()->put(self::PENDING_KEY, [
            'id' => $info['id'],
            'battletag' => $info['battletag'],
            'characters' => $this->client->accountCharacters($token),
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->timestamp,
        ]);

        return redirect()->route('battlenet.finish');
    }

    // ------------------------------------------------------------------ helpers

    private function queueDetailSyncs(BattlenetAccount $account): int
    {
        $eligible = $this->sync->detailEligible($account);

        foreach ($eligible as $character) {
            SyncBattlenetCharacter::dispatch($character->id);
        }

        return $eligible->count();
    }

    /** @return array{id: int, battletag: string, characters: list<array>, expires_at: int}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return $pending;
    }

    /** Signed-in players go back to their characters; guests go back to the login page. */
    private function failure(Request $request, string $message): RedirectResponse
    {
        return $request->user()
            ? redirect()->route('characters.index')->with('battlenet_error', $message)
            : redirect()->route('login')->withErrors(['email' => $message]);
    }
}
