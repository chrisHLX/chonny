<?php

namespace Tests\Feature;

use App\Http\Services\YouTubeService;
use App\Models\ArenaRound;
use App\Models\User;
use App\Models\YoutubeVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Uploading the app's recordings to the player's own YouTube channel (2026-10-26): linking on the
 * website, the app's upload pass with a title and chapters, the video recorded against its game,
 * and its Watch page playing it from YouTube. Google is faked; nothing here sends a video anywhere.
 */
class YouTubeUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $lobby;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.youtube.client_id' => 'yt-client', 'services.youtube.client_secret' => 'yt-secret', 'services.youtube.public' => true]);
        $this->lobby = str_repeat('ab', 16);
    }

    /** Until Google verifies the app only test users can link, so only an admin is offered it (2026-10-27). */
    public function test_before_it_is_public_only_an_admin_is_offered_youtube(): void
    {
        config(['services.youtube.public' => false]);
        $player = User::factory()->create();
        $admin = tap(User::factory()->create())->forceFill(['is_admin' => true]);
        $admin->save();

        $this->withToken($player->issueCoachToken())->getJson(route('api.coach.index'))->assertJsonPath('youtube', false);
        $this->actingAs($player)->get(route('youtube.link'))->assertNotFound();

        $this->withToken($admin->issueCoachToken())->getJson(route('api.coach.index'))->assertJsonPath('youtube', true);
        $this->actingAs($admin)->get(route('youtube.link'))->assertRedirect();
    }

    /** A 3v3 shaped like 25 Oct 19:11: Jungle against RMD, lost, with a fall at 69s. */
    private function game(User $user): void
    {
        $series = fn (array $at) => array_map(fn ($s) => $at[$s] ?? 100, range(0, 120));
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => $this->lobby, 'match_id' => 'm', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-25 19:10:59',
            'payload' => ['analysis' => ['version' => 16, 'won' => false, 'mmr' => ['us' => 1990, 'them' => 1953], 'goes' => [], 'deaths' => [],
                'players' => [
                    ['guid' => 'P-1', 'name' => 'Crawlordx-Frostmourne-US', 'spec' => 'Feral Druid', 'classSlug' => 'druid', 'side' => 'us', 'healer' => false, 'logger' => true],
                    ['guid' => 'P-2', 'name' => 'Doubletapz-Frostmourne-US', 'spec' => 'Beast Mastery Hunter', 'classSlug' => 'hunter', 'side' => 'us', 'healer' => false],
                    ['guid' => 'P-H', 'name' => 'Nearó-Barthilas-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'us', 'healer' => true],
                    ['guid' => 'E-1', 'name' => 'Noitabretsam-Tichondrius-US', 'spec' => 'Fire Mage', 'classSlug' => 'mage', 'side' => 'them', 'healer' => false],
                    ['guid' => 'E-2', 'name' => 'Christopher-Tichondrius-US', 'spec' => 'Subtlety Rogue', 'classSlug' => 'rogue', 'side' => 'them', 'healer' => false],
                    ['guid' => 'E-H', 'name' => 'Netsirk-Tichondrius-US', 'spec' => 'Restoration Druid', 'classSlug' => 'druid', 'side' => 'them', 'healer' => true],
                ],
                'timeline' => ['casts' => [], 'control' => [['P-2', 'E-2', 'Kidney Shot', 'Stun', 67.4, 71.9]], 'hp' => [
                    'P-1' => $series([]), 'P-H' => $series([]), 'E-1' => $series([]), 'E-2' => $series([]), 'E-H' => $series([]),
                    'P-2' => $series([68 => 95, 69 => 80, 70 => 60, 71 => 50, 72 => 41, 73 => 45, 74 => 60, 75 => 70, 76 => 80, 77 => 85, 78 => 88]),
                ]],
            ]],
        ]);
    }

    public function test_linking_stores_the_token_encrypted_and_refuses_a_stale_state(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'refresh-1', 'expires_in' => 3599])]);
        $user = User::factory()->create();

        $go = $this->actingAs($user)->get(route('youtube.link'));
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $go->headers->get('Location'));
        $this->assertStringContainsString('scope='.urlencode(YouTubeService::SCOPE), $go->headers->get('Location'));
        $state = session('youtube_state');

        $this->actingAs($user)->get(route('youtube.callback', ['state' => 'forged', 'code' => 'c']))->assertRedirect();
        $this->assertNull($user->fresh()->youtube_token, 'a callback with the wrong state links nothing');

        session(['youtube_state' => $state]);
        $this->actingAs($user)->get(route('youtube.callback', ['state' => $state, 'code' => 'c']))->assertRedirect(route('coach').'#youtube');
        $this->assertSame('refresh-1', $user->fresh()->youtube_token);
        $this->assertNotSame('refresh-1', DB::table('users')->where('id', $user->id)->value('youtube_token'), 'encrypted at rest');
    }

    public function test_disconnecting_revokes_with_google_and_forgets_the_token(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);
        $user = User::factory()->create();
        $user->forceFill(['youtube_token' => 'refresh-1', 'youtube_linked_at' => now()])->save();

        $this->actingAs($user)->post(route('youtube.disconnect'))->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === 'refresh-1');
        $this->assertNull($user->fresh()->youtube_token);
    }

    public function test_the_app_gets_an_upload_pass_with_a_title_and_chapters_only_once_linked(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived', 'expires_in' => 3599])]);
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        $this->game($user);
        $body = ['lobby' => $this->lobby, 'started' => '2026-10-25 19:10:59.300', 'rounds' => ['2026-10-25 19:10:59.100']];

        $this->withToken($key)->postJson(route('api.coach.youtube.prepare'), $body)->assertStatus(409)->assertJson(['status' => 'not-linked']);

        $user->forceFill(['youtube_token' => 'refresh-1', 'youtube_linked_at' => now()])->save();
        $ok = $this->withToken($key)->postJson(route('api.coach.youtube.prepare'), $body)->assertOk()->json();

        $this->assertSame('short-lived', $ok['accessToken']);
        $this->assertSame('Jungle vs RMD · 1,990 MMR · Feral POV · Loss', $ok['title']);
        $this->assertStringNotContainsString('Doubletapz', $ok['description'], 'specs, never player names');
        $this->assertStringContainsString('0:00 Gates open', $ok['description']);
        $this->assertStringContainsString('1:06 BM Hunter to 41% (control, no cooldowns)', $ok['description']);
    }

    public function test_an_uploaded_video_plays_from_youtube_on_its_watch_page(): void
    {
        $user = User::factory()->create();
        $key = $user->issueCoachToken();
        $this->game($user);

        $this->withToken($key)->postJson(route('api.coach.youtube.uploaded'), [
            'lobby' => $this->lobby, 'videoId' => 'dQw4w9WgXcQ', 'privacy' => 'unlisted',
            'started' => '2026-10-25 19:10:59.300', 'rounds' => ['2026-10-25 19:10:59.100'],
        ])->assertOk();
        $this->assertSame('dQw4w9WgXcQ', YoutubeVideo::where('user_id', $user->id)->value('video_id'));

        $html = $this->withToken($key)->get(route('api.coach.page', "watch-{$this->lobby}.html"))->assertOk()->getContent();
        $this->assertStringContainsString('"youtube":"dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('var blankMode = false', $html);
        $this->assertStringContainsString('"offset":-0.2', $html);

        // Only its own videos: another key cannot attach one to this game's page.
        $this->withToken(User::factory()->create()->issueCoachToken())->postJson(route('api.coach.youtube.uploaded'), ['lobby' => $this->lobby, 'videoId' => 'x', 'privacy' => 'public'])->assertStatus(422);
    }
}
