<?php

namespace Tests\Feature;

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
}
