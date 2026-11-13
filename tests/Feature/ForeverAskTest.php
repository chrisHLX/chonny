<?php

namespace Tests\Feature;

use App\Http\Services\ForeverAskService;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /ask in WoW Forever answered by Gemini with Google Search (ForeverAskService, 2026-11-13).
 *
 * The rules worth testing: a point is kept only with a zone and both coordinates on the map; the
 * same question is answered from memory the second time, by anyone, without another search; each
 * player has a daily limit; and the app's key is required.
 */
class ForeverAskTest extends TestCase
{
    use RefreshDatabase;

    private function gemini(string $text): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]]],
                'groundingMetadata' => [
                    'webSearchQueries' => ['frost oil recipe wow forever'],
                    'groundingChunks' => [['web' => ['uri' => 'https://vertexaisearch.cloud.google.com/x', 'title' => 'wowhead.com']]],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 80],
        ])]);
    }

    private function ask(string $key, array $body)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/forever/ask', $body);
    }

    public function test_an_answer_keeps_only_points_on_the_map_and_is_remembered_for_the_next_player(): void
    {
        $this->gemini("Here you go:\n```json\n".json_encode([
            'answer' => 'Sold by Kor\'geld in Orgrimmar.',
            'points' => [
                ['zone' => 'Orgrimmar', 'x' => 56.2, 'y' => 34.8, 'label' => 'Kor\'geld'],
                ['zone' => 'Orgrimmar', 'x' => 156, 'y' => 34, 'label' => 'off the map'],
                ['zone' => '', 'x' => 10, 'y' => 10],
                ['x' => 10, 'y' => 10],
            ],
        ])."\n```");
        $a = User::factory()->create();
        $b = User::factory()->create();

        $first = $this->ask($a->issueCoachToken(), ['q' => 'Where can I get Recipe: Frost Oil?', 'faction' => 'Horde', 'context' => 'Player: level 40 shaman in Orgrimmar.'])
            ->assertOk()->json();
        $this->assertSame('Sold by Kor\'geld in Orgrimmar.', $first['answer']);
        $this->assertSame([['zone' => 'Orgrimmar', 'x' => 56.2, 'y' => 34.8, 'label' => 'Kor\'geld']], $first['points']);
        $this->assertSame(['wowhead.com'], $first['sources']);
        $this->assertFalse($first['cached']);
        $this->assertSame(ForeverAskService::DAILY_LIMIT - 1, $first['left']);

        // another player, the same question in other words of spacing and case: from memory, no second search
        $second = $this->ask($b->issueCoachToken(), ['q' => 'where can I get   recipe: frost oil?', 'faction' => 'Horde'])->assertOk()->json();
        $this->assertTrue($second['cached']);
        $this->assertSame($first['points'], $second['points']);
        Http::assertSentCount(1);
        $this->assertSame(1, AiRequest::where('purpose', 'forever_ask')->count());

        // the search was asked for with Google Search on, and the player's context went with the question
        Http::assertSent(fn ($r) => isset($r['tools'][0]['google_search']) && str_contains($r['contents'][0]['parts'][0]['text'], 'level 40 shaman'));
    }

    public function test_each_player_has_a_daily_limit(): void
    {
        $this->gemini(json_encode(['answer' => 'In Ashenvale.', 'points' => []]));
        $user = User::factory()->create();
        Cache::put('forever-ask-count:'.$user->id.':'.now()->toDateString(), ForeverAskService::DAILY_LIMIT, now()->endOfDay());

        $this->ask($user->issueCoachToken(), ['q' => 'Where are the bears in Ashenvale?'])->assertStatus(429)->assertJson(['status' => 'limit', 'left' => 0]);
        Http::assertNothingSent();
    }

    public function test_an_answer_without_json_is_a_failure_not_a_guess(): void
    {
        $this->gemini('I think it is somewhere in the Barrens.');
        $user = User::factory()->create();

        $this->ask($user->issueCoachToken(), ['q' => 'Where is the Wailing Caverns?'])->assertStatus(502)->assertJson(['status' => 'failed']);
        // nothing remembered, nothing counted
        $this->assertSame(0, Cache::get('forever-ask-count:'.$user->id.':'.now()->toDateString(), 0));
        $this->assertNull(Cache::get('forever-ask:'.sha1('where is the wailing caverns?|')));
    }

    public function test_the_apps_key_is_required(): void
    {
        $this->postJson('/api/forever/ask', ['q' => 'Where are the bears?'])->assertStatus(401);
    }
}
