<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ForeverGearService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The app sending what the Auction House addon read about WoW Forever's weapons and armour, and the
 * vendors the player opened (2026-11-27, ForeverGearService). Gzipped JSON, with the player's key.
 */
class ForeverGearController extends Controller
{
    /** POST /api/forever/items: a sweep's items, refusals and suffix versions, in parts of a few thousand items (nginx takes 1 MB). */
    public function items(Request $request, ForeverGearService $gear): JsonResponse
    {
        return $this->take($request, 'forever-items', 20, fn (User $u, array $d) => $gear->storeItems($u, $d));
    }

    /** POST /api/forever/vendors: vendor lists. */
    public function vendors(Request $request, ForeverGearService $gear): JsonResponse
    {
        return $this->take($request, 'forever-vendors', 30, fn (User $u, array $d) => $gear->storeVendors($u, $d));
    }

    private function take(Request $request, string $limiter, int $perHour, \Closure $store): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        $key = $limiter.':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return response()->json(['status' => 'rate-limited', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 3600);

        $data = json_decode((string) @gzdecode($request->getContent()), true);
        if (! is_array($data)) {
            return response()->json(['status' => 'rejected', 'reason' => 'Not readable gzipped JSON.'], 422);
        }
        $result = $store($user, $data);

        return response()->json($result, $result['status'] === 'rejected' ? 422 : 200);
    }
}
