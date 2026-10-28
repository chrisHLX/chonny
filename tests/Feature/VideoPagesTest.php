<?php

namespace Tests\Feature;

use App\Models\ArenaRound;
use App\Models\User;
use App\Models\YoutubeVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Games on video (VideoController, 2026-10-28): a page per uploaded video that follows who YouTube
 * lets watch it, names specs and never players, and a library filtered by the specs in each game.
 * YouTube is faked.
 */
class VideoPagesTest extends TestCase
{
    use RefreshDatabase;

    private const NAMES = ['Ashvale', 'Brindlecort', 'Quennimoor', 'Tolvarrin', 'Ystrelda', 'Morrowgast'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.youtube.client_id' => 'yt-client', 'services.youtube.client_secret' => 'yt-secret', 'services.youtube.public' => true, 'services.youtube.api_key' => null]);
    }

    private function video(User $user, string $id, string $privacy, string $lobby): YoutubeVideo
    {
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => $lobby, 'match_id' => 'm'.$lobby, 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-28 13:43:56',
            'payload' => ['analysis' => ['version' => 17, 'won' => true, 'mmr' => ['us' => 2073, 'them' => 2050], 'goes' => [], 'deaths' => [],
                'players' => [
                    ['guid' => 'P-1', 'name' => self::NAMES[0].'-Realm-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'us', 'healer' => true, 'logger' => true],
                    ['guid' => 'P-2', 'name' => self::NAMES[1].'-Realm-US', 'spec' => 'Marksmanship Hunter', 'classSlug' => 'hunter', 'side' => 'us', 'healer' => false],
                    ['guid' => 'P-3', 'name' => self::NAMES[2].'-Realm-US', 'spec' => 'Retribution Paladin', 'classSlug' => 'paladin', 'side' => 'us', 'healer' => false],
                    ['guid' => 'E-1', 'name' => self::NAMES[3].'-Realm-US', 'spec' => 'Arms Warrior', 'classSlug' => 'warrior', 'side' => 'them', 'healer' => false],
                    ['guid' => 'E-2', 'name' => self::NAMES[4].'-Realm-US', 'spec' => 'Balance Druid', 'classSlug' => 'druid', 'side' => 'them', 'healer' => false],
                    ['guid' => 'E-3', 'name' => self::NAMES[5].'-Realm-US', 'spec' => 'Discipline Priest', 'classSlug' => 'priest', 'side' => 'them', 'healer' => true],
                ],
                'timeline' => ['casts' => ['P-2' => [[3.0, 'Aimed Shot', 'E-1']]], 'control' => [], 'hp' => []],
            ]],
        ]);

        return YoutubeVideo::create(['user_id' => $user->id, 'lobby_id' => $lobby, 'video_id' => $id, 'privacy' => $privacy, 'status' => $privacy]);
    }

    public function test_a_public_video_has_a_page_without_names_and_is_in_the_library(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'A game'])]);
        $owner = User::factory()->create();
        $this->video($owner, 'pubVideo001', 'public', str_repeat('a', 32));

        $page = $this->get(route('videos.show', 'pubVideo001'))->assertOk();
        $page->assertSee('Disc Priest', false)->assertSee('Copy link')->assertDontSee('Your video.');
        $timeline = $this->get(route('videos.timeline', 'pubVideo001'))->assertOk()->getContent();
        foreach (self::NAMES as $name) {
            $this->assertStringNotContainsString($name, $timeline, "{$name} is on the public timeline");
            $this->assertStringNotContainsString($name, $page->getContent());
        }
        // Both teams have a Disc Priest: the other team's is told apart.
        $this->assertStringContainsString('Their Disc Priest', $timeline);

        $this->get(route('videos'))->assertOk()->assertSee('pubVideo001');
        $this->get(route('videos', ['spec' => 'hunter/marksmanship']))->assertSee('pubVideo001');
        $this->get(route('videos', ['vs' => 'warrior']))->assertSee('pubVideo001');
        $this->get(route('videos', ['vs' => 'hunter']))->assertDontSee('pubVideo001');
        $this->get(route('videos', ['result' => 'lost']))->assertDontSee('pubVideo001');
        $this->get(route('videos', ['mmr' => 2400]))->assertDontSee('pubVideo001');
    }

    public function test_an_unlisted_video_is_shared_by_link_and_its_owner_can_put_it_in_the_library(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'A game'])]);
        $owner = User::factory()->create();
        $this->video($owner, 'unlVideo001', 'unlisted', str_repeat('b', 32));

        $this->get(route('videos.show', 'unlVideo001'))->assertOk();
        $this->get(route('videos'))->assertDontSee('unlVideo001');

        // Someone else cannot change it.
        $this->actingAs(User::factory()->create())->post(route('coach.videos.listed', 'unlVideo001'), ['listed' => 1])->assertNotFound();

        $this->actingAs($owner)->get(route('videos.show', 'unlVideo001'))->assertSee('Your video.')->assertSee('Unlisted on YouTube');
        $this->actingAs($owner)->post(route('coach.videos.listed', 'unlVideo001'), ['listed' => 1])->assertRedirect();
        $this->get(route('videos'))->assertSee('unlVideo001');
    }

    public function test_a_private_video_is_its_owners_alone(): void
    {
        $owner = User::factory()->create();
        $this->video($owner, 'prvVideo001', 'private', str_repeat('c', 32));
        Http::fake(['www.youtube.com/oembed*' => Http::response('Unauthorized', 401)]);

        $this->get(route('videos.show', 'prvVideo001'))->assertNotFound();
        $this->get(route('videos.timeline', 'prvVideo001'))->assertNotFound();
        $this->actingAs($owner)->get(route('videos.show', 'prvVideo001'))->assertOk()->assertSee('Private on YouTube');
    }

    /** YouTube's answer replaces what was chosen at upload: made private there, the page closes. */
    public function test_what_youtube_says_is_what_counts(): void
    {
        config(['services.youtube.api_key' => 'key']);
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'pubVideo002', 'snippet' => ['title' => 'Renamed'],
                'status' => ['privacyStatus' => 'unlisted', 'uploadStatus' => 'processed', 'embeddable' => true],
                'statistics' => ['viewCount' => '42'],
            ]]]),
            'www.youtube.com/oembed*' => Http::response('Not Found', 404),
        ]);
        $owner = User::factory()->create();
        $v = $this->video($owner, 'pubVideo002', 'public', str_repeat('d', 32));
        $gone = $this->video($owner, 'goneVideo01', 'public', str_repeat('e', 32));

        $this->actingAs($owner)->get(route('coach'))->assertOk()->assertSee('42 views')->assertSee('YouTube no longer has this video.');
        $this->assertSame('unlisted', $v->fresh()->status);
        $this->assertFalse($v->fresh()->inLibrary());
        $this->assertSame('removed', $gone->fresh()->status);
        $this->actingAs(User::factory()->create())->get(route('videos.show', 'goneVideo01'))->assertNotFound();
    }

    public function test_the_app_reads_its_videos_and_an_upload_records_its_game(): void
    {
        Queue::fake();
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'A game'])]);
        $owner = User::factory()->create();
        $key = $owner->issueCoachToken();
        $this->video($owner, 'appVideo001', 'unlisted', str_repeat('f', 32));
        YoutubeVideo::query()->delete();

        $this->withToken($key)->postJson(route('api.coach.youtube.uploaded'), ['lobby' => str_repeat('f', 32), 'videoId' => 'appVideo001', 'privacy' => 'unlisted'])
            ->assertOk()->assertJsonPath('page', route('videos.show', 'appVideo001'));
        $this->assertSame('3v3', YoutubeVideo::first()->game['bracket']);

        $this->withToken($key)->getJson(route('api.coach.youtube.videos'))->assertOk()
            ->assertJsonPath('videos.0.videoId', 'appVideo001')
            ->assertJsonPath('videos.0.watchable', true)
            ->assertJsonPath('videos.0.inLibrary', false)
            ->assertJsonPath('videos.0.page', route('videos.show', 'appVideo001'));
    }
}
