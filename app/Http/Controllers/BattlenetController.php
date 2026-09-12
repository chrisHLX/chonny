<?php

namespace App\Http\Controllers;

use App\Http\Services\BattlenetAccountTakenException;
use App\Http\Services\BattlenetApiException;
use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\BattlenetClient;
use App\Jobs\SyncBattlenetCharacter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Linking a Battle.net account: out to Blizzard's sign-in, back with a code, and unlinking.
 *
 * The OAuth `state` is a random value held in the session and compared on return — the thing that
 * stops someone completing a link on another person's session by getting them to open a callback
 * URL. It is pulled (read once, then removed), so a callback URL cannot be replayed either.
 *
 * Re-running the flow is how the character LIST is refreshed (a new alt, a transfer): the list needs
 * the player's own token, and Blizzard issues no refresh token to renew it without them.
 */
class BattlenetController extends Controller
{
    private const STATE_KEY = 'battlenet_oauth_state';

    public function __construct(
        private BattlenetClient $client,
        private BattlenetCharacterSyncService $sync,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            return redirect()->route('characters.index')
                ->with('battlenet_error', 'Battle.net linking is not configured on this server.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->client->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $back = redirect()->route('characters.index');

        // The player pressed Cancel on Blizzard's consent screen.
        if ($request->filled('error')) {
            return $back->with('battlenet_error', 'Battle.net sign-in was cancelled — nothing was linked.');
        }

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state', ''))
            || ! $request->filled('code')) {
            return $back->with('battlenet_error', 'That sign-in link had expired or was not started here. Please try again.');
        }

        try {
            $token = $this->client->exchangeCode((string) $request->query('code'));
            $account = $this->sync->linkAccount($request->user(), $token);
        } catch (BattlenetAccountTakenException $e) {
            return $back->with('battlenet_error', $e->getMessage());
        } catch (BattlenetApiException $e) {
            // Blizzard's own refusal (a redirect URL not registered on the client, say) — worth
            // showing verbatim, it is usually the whole diagnosis.
            report($e);

            return $back->with('battlenet_error', 'Could not link Battle.net: '.$e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $back->with('battlenet_error', 'Could not link Battle.net — something went wrong on our side. Please try again.');
        }

        $eligible = $this->sync->detailEligible($account);

        foreach ($eligible as $character) {
            SyncBattlenetCharacter::dispatch($character->id);
        }

        return $back->with('battlenet_status', "Linked {$account->battletag}. Fetching ratings, gear and talents for {$eligible->count()} "
            .Str::plural('character', $eligible->count()).' — this takes a moment.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Cascades to its characters; any guide attributed to one keeps the guide and loses the
        // attribution (user_guides.battlenet_character_id is nullOnDelete).
        $request->user()->battlenetAccount?->delete();

        return redirect()->route('characters.index')
            ->with('battlenet_status', 'Battle.net unlinked. Your characters were removed from MindCollector.');
    }
}
