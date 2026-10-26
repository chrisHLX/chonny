<?php

namespace Tests\Feature;

use App\Http\Services\ArenaReviewIngestService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A round with no ARENA_MATCH_END ends where the player leaves the arena (2026-10-27), and a round
 * still too large is skipped, not rejected, so an app before 0.6.2 stops re-sending its log.
 */
class RoundUntilLeavingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_round_ends_at_the_zone_change_that_leaves_the_arena(): void
    {
        $round = implode("\n", [
            '10/26/2026 16:53:28.61711  ARENA_MATCH_START,1505,42,3v3,0',
            '10/26/2026 16:53:29.00011  SPELL_CAST_SUCCESS,a',
            '10/26/2026 16:58:00.00011  ZONE_CHANGE,2563,"Silvermoon City",0',
            '10/26/2026 17:30:00.00011  SPELL_CAST_SUCCESS,questing',
        ]);

        $this->assertSame(implode("\n", [
            '10/26/2026 16:53:28.61711  ARENA_MATCH_START,1505,42,3v3,0',
            '10/26/2026 16:53:29.00011  SPELL_CAST_SUCCESS,a',
        ]), ArenaReviewIngestService::untilLeaving($round));
    }

    public function test_a_round_that_never_leaves_is_untouched(): void
    {
        $round = "10/26/2026 16:53:28.61711  ARENA_MATCH_START,1505,42,3v3,0\n10/26/2026 16:55:00.00011  ARENA_MATCH_END,0,84,2379,2373";

        $this->assertSame($round, ArenaReviewIngestService::untilLeaving($round));
    }

    public function test_a_round_too_large_even_so_is_skipped_so_the_app_moves_on(): void
    {
        $round = '10/26/2026 16:53:28.61711  ARENA_MATCH_START,1505,42,3v3,0'."\n".str_repeat('x', ArenaReviewIngestService::MAX_ROUND_BYTES);

        $result = app(ArenaReviewIngestService::class)->ingestRound(User::factory()->create(), $round);

        $this->assertSame('skipped', $result['status']);
    }
}
