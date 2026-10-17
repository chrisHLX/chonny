<?php

namespace Tests\Unit;

use App\Support\LiveOrCommitted;
use Tests\TestCase;

/** The live copy of a built file wins when newer, unless it rests on less than half the games. */
class LiveOrCommittedTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function file(string $builtAt, int $games): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mc');
        file_put_contents($path, json_encode(['builtAt' => $builtAt, 'generatedAt' => substr($builtAt, 0, 10), 'games' => $games, 'specs' => []], JSON_PRETTY_PRINT));

        return $this->files[] = $path;
    }

    public function test_the_newer_copy_wins(): void
    {
        $committed = $this->file('2026-10-17T07:00:00+00:00', 1064);
        $live = $this->file('2026-10-17T08:00:00+00:00', 1088);

        $this->assertSame($live, LiveOrCommitted::pick($committed, $live));
        $this->assertSame($committed, LiveOrCommitted::pick($committed, $this->file('2026-10-17T06:00:00+00:00', 1088)));
    }

    public function test_a_newer_copy_built_from_a_fraction_of_the_games_does_not(): void
    {
        // 17 Oct: the deploy's rebuild after a VERSION change held 24 games against 1,064.
        $committed = $this->file('2026-10-17T07:00:00+00:00', 1064);
        $live = $this->file('2026-10-17T08:00:00+00:00', 24);

        $this->assertSame($committed, LiveOrCommitted::pick($committed, $live));
    }

    public function test_a_missing_copy_is_never_picked(): void
    {
        $committed = $this->file('2026-10-17T07:00:00+00:00', 1064);

        $this->assertSame($committed, LiveOrCommitted::pick($committed, '/no/such/live.json'));
    }
}
