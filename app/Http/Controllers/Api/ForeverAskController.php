<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ForeverAskService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * The desktop app asking a WoW Forever /ask question (2026-11-13, ForeverAskService): Gemini with
 * Google Search, for players without Claude Code. With the player's key, like the scan uploads.
 */
class ForeverAskController extends Controller
{
    /** POST /api/forever/ask: {q, context?, faction?} -> {status, answer, points, sources, cached, left}. */
    public function ask(Request $request, ForeverAskService $asks): JsonResponse
    {
        $user = User::forCoachToken($request->bearerToken());
        if (! $user) {
            return response()->json(['status' => 'unauthorised'], 401);
        }
        // a burst guard; the daily limit is the service's
        $key = 'forever-ask:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['status' => 'rate-limited', 'reason' => 'One moment: a few questions at once is the limit.', 'retryAfter' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 60);

        $v = Validator::make($request->json()->all(), [
            'q' => ['required', 'string', 'min:3', 'max:300'],
            'context' => ['nullable', 'string', 'max:4000'],
            'faction' => ['nullable', 'in:Alliance,Horde'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'rejected', 'reason' => $v->errors()->first()], 422);
        }
        $q = $v->validated();

        $result = $asks->answer($user, $q['q'], $q['context'] ?? '', $q['faction'] ?? null);

        return response()->json($result, match ($result['status']) {
            'ok' => 200, 'limit' => 429, default => 502
        });
    }
}
