<?php

namespace App\Http\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Battle.net OAuth and the WoW profile API — every HTTP call to Blizzard goes through here.
 *
 * Plain HTTP rather than Socialite, same as the recaptcha check: the flow is three documented
 * requests, and a package would be the largest moving part in it.
 *
 * TWO TOKENS, DELIBERATELY KEPT APART:
 * - The USER token comes from the authorization-code flow and is used for exactly two calls,
 *   /userinfo and /profile/user/wow, both in the callback request. It is never stored (see
 *   create_battlenet_tables for why).
 * - The APP token (client credentials) reads everything else. Blizzard's character profile
 *   endpoints are public — confirmed live 2026-09-12, every one of summary, statistics,
 *   achievements, pvp-summary, pvp-bracket, specializations and equipment returns 200 on an app
 *   token — which is what lets a character be refreshed without the player logging in again.
 *
 * Tokens from oauth.battle.net are region-agnostic for every region but China, so one link reads
 * characters from us, eu, kr and tw.
 */
class BattlenetClient
{
    public const OAUTH_HOST = 'https://oauth.battle.net';

    private const APP_TOKEN_CACHE_KEY = 'battlenet:app_token';

    private const SCOPES = 'openid wow.profile';

    public function isConfigured(): bool
    {
        return filled(config('services.battlenet.client_id')) && filled(config('services.battlenet.client_secret'));
    }

    /** Must match a Redirect URL registered on the Blizzard client EXACTLY, scheme and all. */
    public function redirectUri(): string
    {
        return config('services.battlenet.redirect') ?: route('battlenet.callback');
    }

    public function authorizeUrl(string $state): string
    {
        return self::OAUTH_HOST.'/authorize?'.http_build_query([
            'client_id' => config('services.battlenet.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Trade an authorization code for a user access token. */
    public function exchangeCode(string $code): string
    {
        $response = $this->oauthRequest()->post(self::OAUTH_HOST.'/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            throw new BattlenetApiException('Battle.net rejected the sign-in ('.$response->status().': '.($response->json('error_description') ?? $response->json('error') ?? 'no detail').').');
        }

        return $token;
    }

    /** @return array{id: int, battletag: string} */
    public function userInfo(string $userToken): array
    {
        $response = $this->http()->withToken($userToken)->get(self::OAUTH_HOST.'/userinfo');

        if (! $response->successful() || ! $response->json('id')) {
            throw new BattlenetApiException('Could not read the Battle.net account ('.$response->status().').');
        }

        return ['id' => (int) $response->json('id'), 'battletag' => (string) $response->json('battletag', 'Unknown')];
    }

    /**
     * Every WoW character on the account, across every supported region.
     *
     * A region the account has no WoW licence on answers 404 and is simply skipped. Any other
     * failure throws: a partial list must never be treated as the whole list, because the caller
     * deletes characters that are missing from it.
     *
     * @return list<array{region: string, id: int, name: string, realm_slug: string, realm_name: string, class_id: ?int, race: ?string, faction: ?string, level: int}>
     */
    public function accountCharacters(string $userToken): array
    {
        $out = [];

        foreach (config('services.battlenet.regions', ['us']) as $region) {
            $response = $this->http()->withToken($userToken)
                ->get("https://{$region}.api.blizzard.com/profile/user/wow", [
                    'namespace' => "profile-{$region}",
                    'locale' => 'en_US',
                ]);

            if ($response->status() === 404) {
                continue;
            }

            if (! $response->successful()) {
                throw new BattlenetApiException("Battle.net did not return the {$region} character list ({$response->status()}).");
            }

            foreach ($response->json('wow_accounts', []) as $wowAccount) {
                foreach ($wowAccount['characters'] ?? [] as $c) {
                    if (empty($c['id']) || empty($c['name']) || empty($c['realm']['slug'])) {
                        continue;
                    }

                    $out[] = [
                        'region' => $region,
                        'id' => (int) $c['id'],
                        'name' => (string) $c['name'],
                        'realm_slug' => (string) $c['realm']['slug'],
                        'realm_name' => (string) ($c['realm']['name'] ?? $c['realm']['slug']),
                        'class_id' => isset($c['playable_class']['id']) ? (int) $c['playable_class']['id'] : null,
                        'race' => $c['playable_race']['name'] ?? null,
                        'faction' => $c['faction']['name'] ?? null,
                        'level' => (int) ($c['level'] ?? 0),
                    ];
                }
            }
        }

        return $out;
    }

    /** GET one Game Data endpoint ($namespace is static | dynamic) on the app token; null on 404. */
    public function gameData(string $region, string $path, string $namespace = 'static'): ?array
    {
        return $this->appGet("https://{$region}.api.blizzard.com{$path}", [
            'namespace' => "{$namespace}-{$region}",
            'locale' => 'en_US',
        ]);
    }

    /**
     * Several character endpoints at once, CONCURRENTLY. Blizzard answers each request in ~0.8s
     * (measured 2026-09-12), so the nine a warm sync needs took 7.5s one after another — too slow
     * behind a Refresh button. None depends on another, so they go out together.
     *
     * @param  array<string, string>  $paths  key => path ('' for the summary)
     * @return array<string, ?array> same keys; null where Blizzard answered 404
     */
    public function characterMany(string $region, string $realmSlug, string $name, array $paths): array
    {
        $base = "https://{$region}.api.blizzard.com/profile/wow/character/{$realmSlug}/".rawurlencode(mb_strtolower($name));

        return $this->appGetMany(
            array_map(fn (string $path) => $base.$path, $paths),
            ['namespace' => "profile-{$region}", 'locale' => 'en_US'],
        );
    }

    /**
     * Several Game Data endpoints at once — see characterMany().
     *
     * @param  array<array-key, string>  $paths
     * @return array<array-key, ?array>
     */
    public function gameDataMany(string $region, array $paths, string $namespace = 'static'): array
    {
        return $this->appGetMany(
            array_map(fn (string $path) => "https://{$region}.api.blizzard.com{$path}", $paths),
            ['namespace' => "{$namespace}-{$region}", 'locale' => 'en_US'],
        );
    }

    /**
     * Download several files (item icons) concurrently. Bytes per key, or null for any that failed
     * — an icon is decoration, and one that will not download must not fail anything.
     *
     * @param  array<array-key, string>  $urls
     * @return array<array-key, ?string>
     */
    public function downloadMany(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => collect($urls)
            ->map(fn (string $url, $key) => $pool->as((string) $key)->connectTimeout(10)->timeout(20)->get($url))
            ->all());

        $out = [];
        foreach ($urls as $key => $url) {
            $response = $responses[(string) $key] ?? null;
            $out[$key] = $response instanceof Response && $response->successful() ? $response->body() : null;
        }

        return $out;
    }

    /** @return array<array-key, ?array> */
    private function appGetMany(array $urls, array $query): array
    {
        if ($urls === []) {
            return [];
        }

        $token = $this->appToken();

        $responses = Http::pool(fn (Pool $pool) => collect($urls)
            ->map(fn (string $url, $key) => $pool->as((string) $key)->withToken($token)->connectTimeout(10)->timeout(20)->get($url, $query))
            ->all());

        $out = [];

        foreach ($urls as $key => $url) {
            $response = $responses[(string) $key] ?? null;

            // A dropped connection (the pool hands back the exception) or a token Blizzard expired
            // early: redo just this one through the one-at-a-time path, which retries both.
            if (! $response instanceof Response || $response->status() === 401) {
                $out[$key] = $this->appGet($url, $query);

                continue;
            }

            if ($response->status() === 404) {
                $out[$key] = null;

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Battle.net API request failed', ['url' => $url, 'status' => $response->status()]);

                throw new BattlenetApiException("Blizzard's API returned {$response->status()}.");
            }

            $out[$key] = $response->json();
        }

        return $out;
    }

    private function appGet(string $url, array $query, bool $retried = false): ?array
    {
        $response = $this->http()->withToken($this->appToken())->get($url, $query);

        if ($response->status() === 404) {
            return null;
        }

        // A cached token Blizzard has already expired (they can end early). Forget it and try
        // once with a fresh one rather than failing a whole sync over it.
        if ($response->status() === 401 && ! $retried) {
            Cache::forget(self::APP_TOKEN_CACHE_KEY);

            return $this->appGet($url, $query, true);
        }

        if (! $response->successful()) {
            Log::warning('Battle.net API request failed', ['url' => $url, 'status' => $response->status()]);

            throw new BattlenetApiException("Blizzard's API returned {$response->status()}.");
        }

        return $response->json();
    }

    private function appToken(): string
    {
        return Cache::remember(self::APP_TOKEN_CACHE_KEY, now()->addHours(12), function () {
            $response = $this->oauthRequest()->post(self::OAUTH_HOST.'/token', ['grant_type' => 'client_credentials']);

            $token = $response->json('access_token');

            if (! $response->successful() || ! $token) {
                throw new BattlenetApiException('Could not get an app token from Battle.net ('.$response->status().').');
            }

            return $token;
        });
    }

    private function oauthRequest(): PendingRequest
    {
        return $this->http()->asForm()->withBasicAuth(
            (string) config('services.battlenet.client_id'),
            (string) config('services.battlenet.client_secret'),
        );
    }

    /** Retry only on a dropped connection — a 404 or a 401 is an answer, not a hiccup. */
    private function http(): PendingRequest
    {
        return Http::connectTimeout(10)
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }
}
