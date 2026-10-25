<?php

namespace Tests\Feature;

use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Watch page draws a game's measured goes as windows on a timeline, beside the player's own
 * recording (resources/views/desktop/watch.blade.php, built by `wow:watch-page`).
 *
 * The geometry is what is worth testing. A go is a stretch, not a point, so a block's left and
 * width have to match its from/to against the round's length; and a lobby's later rounds have to
 * be placed by the real time between them, because one recording covers all six. Get either wrong
 * and the page still renders, looks plausible, and points at the wrong moment of the video.
 */
class WatchPageTest extends TestCase
{
    use RefreshDatabase;

    private function round(array $over = []): array
    {
        return array_merge([
            'label' => 'Round 1', 'result' => 'won', 'duration' => 180, 'startOffset' => 0,
            'goes' => [], 'deaths' => [],
        ], $over);
    }

    private function render(array $rounds, array $over = []): string
    {
        return view('desktop.watch', array_merge([
            'title' => 'Test game', 'playedAt' => '19 Oct 2026, 21:14', 'suggest' => null,
            'rounds' => $rounds,
        ], $over))->render();
    }

    public function test_a_go_is_drawn_as_a_window_at_the_right_place(): void
    {
        $html = $this->render([$this->round(['goes' => [
            ['side' => 'us', 'from' => 8.0, 'to' => 27.5, 'kill' => null, 'target' => 'Them', 'chain' => 'Bash, Convoke'],
            ['side' => 'them', 'from' => 90.0, 'to' => 108.0, 'kill' => true, 'target' => 'You', 'chain' => 'Chaos Bolt'],
        ]])]);

        // 8/180 = 4.44%, (27.5-8)/180 = 10.83%
        $this->assertStringContainsString('left: 4.4444444444444%; width: 10.833333333333%', $html);
        // 90/180 = 50%, 18/180 = 10%
        $this->assertStringContainsString('left: 50%; width: 10%', $html);
        $this->assertStringContainsString('class="go us"', $html);
        $this->assertStringContainsString('class="go them kill"', $html, 'a go that killed is marked');
    }

    public function test_later_rounds_carry_their_offset_into_the_recording(): void
    {
        $html = $this->render([
            $this->round(['duration' => 180, 'startOffset' => 0]),
            $this->round(['label' => 'Round 2', 'duration' => 95, 'startOffset' => 235]),
        ]);

        // The player maps video time to round time with these, so they must survive to the markup.
        $this->assertStringContainsString('data-start="0" data-dur="180"', $html);
        $this->assertStringContainsString('data-start="235" data-dur="95"', $html);
        $this->assertStringContainsString('starts 3:55 into the recording', $html);
    }

    public function test_a_death_is_a_marker_and_every_event_is_seekable(): void
    {
        $html = $this->render([$this->round([
            'duration' => 200,
            'goes' => [['side' => 'us', 'from' => 10.0, 'to' => 20.0, 'kill' => null, 'target' => 'Them', 'chain' => '']],
            'deaths' => [['t' => 150.0, 'side' => 'us', 'name' => 'Dzdv-Realm', 'killingBlow' => ['spell' => 'Chaos Bolt']]],
        ])]);

        $this->assertStringContainsString('class="death" style="left: 75%"', $html);
        $this->assertStringContainsString('data-t="10"', $html);
        $this->assertStringContainsString('data-t="150"', $html);
        $this->assertStringContainsString('Chaos Bolt', $html);
    }

    public function test_nothing_is_uploaded_and_an_empty_round_still_renders(): void
    {
        $html = $this->render([$this->round()]);

        $this->assertStringContainsString('Nothing measured in this round.', $html);
        // These games name other players (see CoachController): an upload path here is a privacy
        // bug, not a feature. The file is read in the page and never sent anywhere.
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('createObjectURL', $html);
        $this->assertStringContainsString('never uploaded', $html);
    }

    public function test_a_matched_recording_is_only_ever_a_suggestion(): void
    {
        $html = $this->render([$this->round()], ['suggest' => '2026-10-19 21-13-58.mkv']);

        $this->assertStringContainsString('2026-10-19 21-13-58.mkv', $html);
        // Named, never auto-loaded: a browser cannot open a local path without the user choosing it,
        // and guessing wrong silently would be worse than asking.
        $this->assertStringContainsString('Choose a video', $html);
    }

    public function test_the_command_writes_a_page_without_an_archive(): void
    {
        $dir = 'storage/app/testing-watch';
        $this->artisan('wow:watch-page', ['--demo' => true, '--out' => $dir])->assertSuccessful();

        $path = base_path($dir.'/demo.html');
        $this->assertFileExists($path);
        $this->assertStringContainsString('class="go ', File::get($path));

        File::deleteDirectory(base_path($dir));
    }

    public function test_a_recording_made_by_the_app_opens_itself_already_lined_up(): void
    {
        $html = $this->render([$this->round()], ['video' => [
            'name' => '2026-10-25 11-04-41.mp4', 'url' => 'file:///D:/MindCollector/videos/2026-10-25%2011-04-41.mp4', 'offset' => 58.86,
        ]]);

        $this->assertStringContainsString('"offset":58.86', $html);
        $this->assertStringContainsString('2026-10-25%2011-04-41.mp4', $html);
        $this->assertStringContainsString('from MindCollector\'s recording', $html);
        // Still never uploaded, and the picker stays for a file that has moved.
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('Choose a video', $html);
    }

    /**
     * The timing file the desktop app writes beside each video (tools/desktop-app/Recorder.cs),
     * in its real shape, with the 25 Oct shuffle's own ARENA_MATCH_START times. Round 1 sits at
     * its START minus the recording's first frame; round 2 at the gap between the two STARTs to
     * the millisecond, where played_at alone would have cut it to the second.
     */
    public function test_the_command_lines_a_lobby_up_from_the_apps_timing_file(): void
    {
        $user = User::factory()->create();
        foreach (['2026-10-25 11:05:40', '2026-10-25 11:07:27'] as $i => $at) {
            ArenaRound::create([
                'user_id' => $user->id, 'lobby_id' => 'lobby-1', 'match_id' => 'm'.$i, 'roster_key' => 'r',
                'bracket' => 'Rated Solo Shuffle', 'sequence' => $i + 1, 'played_at' => $at,
                'payload' => ['analysis' => ['won' => true, 'goes' => [], 'deaths' => [['t' => 60.0, 'side' => 'them', 'name' => 'X-Realm']]]],
            ]);
        }

        $videos = storage_path('app/testing-watch-videos');
        File::ensureDirectoryExists($videos);
        File::put($videos.'/2026-10-25 11-04-41.mp4', 'not really a video');
        File::put($videos.'/2026-10-25 11-04-41.mp4.mindcollector.json', json_encode([
            'format' => 1,
            'video' => '2026-10-25 11-04-41.mp4',
            'recordingStarted' => '2026-10-25 11:04:41.200',
            'recordingStopped' => '2026-10-25 11:16:02.900',
            'bracket' => 'Rated Solo Shuffle',
            'rounds' => ['2026-10-25 11:05:40.063', '2026-10-25 11:07:27.502'],
        ]));

        $out = 'storage/app/testing-watch';
        $this->artisan('wow:watch-page', ['--user' => $user->id, '--videos' => $videos, '--out' => $out])->assertSuccessful();
        $html = File::get(base_path($out.'/watch-lobby-1.html'));

        // 11:05:40.063 - 11:04:41.200 = 58.863s
        $this->assertStringContainsString('"offset":58.86', $html);
        // 11:07:27.502 - 11:05:40.063 = 107.439s, not the 107 that played_at would give
        $this->assertStringContainsString('data-start="107.44"', $html);
        $this->assertStringContainsString('2026-10-25%2011-04-41.mp4', $html);
        $this->assertStringNotContainsString('Probably <code>', $html, 'an exact recording is not a guess');

        File::deleteDirectory(base_path($out));
        File::deleteDirectory($videos);
    }

    public function test_defensives_are_drawn_and_listed_by_name(): void
    {
        $html = $this->render([$this->round(['duration' => 100, 'defensives' => [
            ['t' => 40.3, 'side' => 'us', 'spell' => 'Pain Suppression', 'who' => 'Nearó', 'on' => 'Crawlordx'],
            ['t' => 59.67, 'side' => 'them', 'spell' => 'Blur', 'who' => 'Mioly', 'on' => null],
        ]])]);

        $this->assertStringContainsString('class="def us" style="left: 40.3%"', $html);
        $this->assertStringContainsString('class="def them"', $html);
        $this->assertStringContainsString('Nearó on Crawlordx', $html);
        $this->assertStringContainsString('data-kind="def"', $html);
    }

    /**
     * The app's Videos tab asks the website for a game's page with what the video's timing file
     * says, and plays the video from the player's own disk (never uploaded) under a made-up host.
     */
    public function test_the_app_gets_a_games_watch_page_lined_up_with_its_video(): void
    {
        $user = User::factory()->create();
        $token = $user->issueCoachToken();
        $lobby = str_repeat('ab', 16);
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => $lobby, 'match_id' => 'm', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-25 16:15:07',
            'payload' => ['analysis' => ['won' => true, 'deaths' => [],
                'goes' => [['side' => 'us', 'from' => 20.4, 'to' => 40.0, 'target' => 'P-2', 'chain' => 'Rake']],
                'players' => [['guid' => 'P-1', 'name' => 'Crawlordx-Frostmourne-US'], ['guid' => 'P-2', 'name' => 'Nearó-Barthilas-US']],
                'defensives' => ['us' => ['rows' => [['t' => 40.3, 'spell' => 'Pain Suppression', 'who' => 'P-2', 'on' => 'P-1']]], 'them' => ['rows' => []]]]],
        ]);
        $query = ['video' => '2026-10-25 16-15-08.mp4', 'started' => '2026-10-25 16:15:08.405', 'rounds' => ['2026-10-25 16:15:07.822']];

        $html = $this->withToken($token)->get(route('api.coach.watch', $lobby).'?'.http_build_query($query))->assertOk()->getContent();

        $this->assertStringContainsString('https:\/\/videos.mindcollector.invalid\/2026-10-25%2016-15-08.mp4', $html);
        $this->assertStringContainsString('"offset":-0.58', $html);
        $this->assertStringContainsString('Nearó on Crawlordx', $html);
        $this->assertStringContainsString('Go on Nearó', $html, 'a go names its target, not its GUID');
        $this->assertStringContainsString('mc-app://game/'.$lobby, $html);

        // Only the key's own games.
        $other = User::factory()->create()->issueCoachToken();
        $this->withToken($other)->get(route('api.coach.watch', $lobby))->assertNotFound();
        $this->withToken('mc_wrong')->get(route('api.coach.watch', $lobby))->assertUnauthorized();
    }

    /**
     * A recording started at the gates has its first frame after the START line (25 Oct 16:15: START
     * 07.822, first frame 08.405), so it is found by its rounds, not by its first frame.
     */
    public function test_a_recording_that_starts_just_after_the_gates_is_still_found(): void
    {
        $user = User::factory()->create();
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => 'lobby-2', 'match_id' => 'm', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-25 16:15:07',
            'payload' => ['analysis' => ['won' => true, 'goes' => [], 'deaths' => []]],
        ]);

        $videos = storage_path('app/testing-watch-videos');
        File::ensureDirectoryExists($videos);
        File::put($videos.'/2026-10-25 16-15-08.mp4', 'not really a video');
        File::put($videos.'/2026-10-25 16-15-08.mp4.mindcollector.json', json_encode([
            'format' => 1,
            'recordingStarted' => '2026-10-25 16:15:08.405',
            'recordingStopped' => '2026-10-25 16:16:40.651',
            'bracket' => '3v3',
            'rounds' => ['2026-10-25 16:15:07.822'],
        ]));

        $out = 'storage/app/testing-watch';
        $this->artisan('wow:watch-page', ['--user' => $user->id, '--videos' => $videos, '--out' => $out])->assertSuccessful();
        $html = File::get(base_path($out.'/watch-lobby-2.html'));

        $this->assertStringContainsString('2026-10-25%2016-15-08.mp4', $html);
        $this->assertStringContainsString('"offset":-0.58', $html);

        File::deleteDirectory(base_path($out));
        File::deleteDirectory($videos);
    }
}
