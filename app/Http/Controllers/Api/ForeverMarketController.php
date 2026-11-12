<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ForeverMarketService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * The app sharing WoW Forever auction house scans, and fetching everyone's (2026-11-12,
 * ForeverMarketService). With the player's key, like the arena uploads.
 */
class ForeverMarketController extends Controller
{
    private const MARKET_RULES = [
        'region' => ['required', 'string', 'regex:/^[A-Za-z]{2,8}$/'],
        'realm' => ['required', 'string', 'max:64', 'regex:/^[^\/]+$/u'],
        'faction' => ['required', 'in:Alliance,Horde,Neutral'],
    ];

    /** POST /api/forever/scan: one scan, gzipped JSON (ForeverMarketService::store). */
    public function scan(Request $request, ForeverMarketService $market): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        // The game allows one full scan every 15 minutes; a few more covers a backlog after a break.
        $key = 'forever-scan:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 12)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 3600);

        $scan = json_decode((string) @gzdecode($request->getContent()), true);
        if (! is_array($scan)) {
            return response()->json(['status' => 'rejected', 'reason' => 'Not readable gzipped JSON.'], 422);
        }
        $v = Validator::make($scan, self::MARKET_RULES + [
            't' => ['required', 'integer'],
            'n' => ['required', 'integer', 'min:1', 'max:5000000'],
            'items' => ['required', 'array', 'min:1', 'max:50000'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'rejected', 'reason' => $v->errors()->first()], 422);
        }

        $result = $market->store($user, $scan);

        return response()->json($result, $result['status'] === 'rejected' ? 422 : 200);
    }

    /** GET /api/forever/market?region=&realm=&faction=: everyone's prices for that market. */
    public function market(Request $request, ForeverMarketService $market): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        $key = 'forever-market:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 3600);

        $v = Validator::make($request->query(), self::MARKET_RULES);
        if ($v->fails()) {
            return response()->json(['status' => 'rejected', 'reason' => $v->errors()->first()], 422);
        }
        $q = $v->validated();

        return response()->json($market->pooled(ForeverMarketService::market($q['region'], $q['realm'], $q['faction'])));
    }
}
