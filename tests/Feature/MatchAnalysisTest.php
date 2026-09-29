<?php

namespace Tests\Feature;

use App\Http\Services\MatchAnalysisService;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "Your analysis": uploaded games combined into the wins-against-losses read, private to the
 * player who uploaded them. Built from stored analysis payloads (the shape RoundAnalysisService
 * writes), so these tests need no combat log.
 */
class MatchAnalysisTest extends TestCase
{
    use RefreshDatabase;

    /** One stored game's analysis, in RoundAnalysisService's shape, with only what the page reads. */
    private function storeGame(User $user, string $matchId, string $playedAt, bool $won, array $over = []): void
    {
        $players = [
            ['guid' => 'P-1', 'name' => 'Healz-Realm-US', 'spec' => 'Discipline Priest', 'specExternalId' => 256, 'side' => 'us', 'healer' => true, 'logger' => true],
            ['guid' => 'P-2', 'name' => 'Dk-Realm-US', 'spec' => 'Unholy Death Knight', 'specExternalId' => 252, 'side' => 'us', 'healer' => false, 'logger' => false],
            ['guid' => 'E-1', 'name' => 'Druid-Realm-US', 'spec' => 'Restoration Druid', 'specExternalId' => 105, 'side' => 'them', 'healer' => true, 'logger' => false],
            ['guid' => 'E-2', 'name' => 'Mage-Realm-US', 'spec' => 'Frost Mage', 'specExternalId' => 64, 'side' => 'them', 'healer' => false, 'logger' => false],
        ];
        $go = fn (bool $kill, int $drained, bool $aligned) => [
            'side' => 'us', 'from' => 10, 'to' => 30, 'good' => true, 'chain' => 'Army of the Dead', 'healerCc' => 1,
            'defs' => 2, 'defNames' => [], 'drained' => $drained, 'kill' => null, 'killLater' => $kill,
            'peak' => ['damage' => 1000000, 'joint' => true, 'healerLocked' => $aligned ? 3.0 : 0.0, 'healerKicked' => false, 'abilities' => []],
            'ownHealerLockedAtCds' => false,
            'links' => [
                ['t' => 0.0, 'spell' => 'Psychic Scream', 'cat' => 'control', 'role' => 'healer', 'by' => 'P-1', 'on' => 'E-1', 'gap' => 0.0],
                ['t' => 1.0, 'spell' => 'Army of the Dead', 'cat' => 'offensive', 'role' => null, 'by' => 'P-2', 'on' => null, 'gap' => 0.0],
            ],
            'forced' => [['t' => 3.0, 'spell' => 'Ironbark', 'who' => 'E-1']],
            'burst' => [['who' => 'P-2', 'spell' => 'Vampiric Strike', 'amount' => 400000]],
            'target' => 'E-2',
        ];

        ArenaRound::create([
            'user_id' => $user->id, 'match_id' => $matchId, 'lobby_id' => $matchId, 'roster_key' => 'x', 'sequence' => 1,
            'bracket' => '3v3', 'played_at' => $playedAt,
            'payload' => ['metadata' => [], 'analysis' => array_merge([
                'version' => 1,
                'won' => $won,
                'mmr' => ['us' => 2000, 'them' => $won ? 1980 : 2100],
                'players' => $players,
                'goes' => $won ? [$go(true, 2, true), $go(false, 0, false)] : [$go(false, 0, false)],
                'deaths' => [[
                    't' => 60, 'who' => $won ? 'E-2' : 'P-2', 'side' => $won ? 'them' : 'us',
                    'killingBlow' => ['spell' => 'Execute', 'amount' => 90000, 'hpBefore' => 5],
                    'shares' => [], 'goStartedAgo' => 8, 'defensives30s' => [],
                    'healer' => ['state' => $won ? 'none' : 'locked', 'endedAgo' => null, 'medallionUsedAt' => $won ? [] : [20]],
                ]],
                'defensives' => ['us' => ['spent' => 3, 'outsideTheirGoes' => 0], 'them' => ['spent' => 4, 'outsideTheirGoes' => 0]],
                'overlaps' => ['us' => $won ? 0 : 2, 'them' => 0],
                'kicks' => [],
                'lockout' => [],
            ], $over)],
        ]);
    }

    public function test_the_page_needs_an_account(): void
    {
        $this->get(route('match-analysis'))->assertRedirect(route('login'));
    }

    public function test_a_player_with_no_games_is_told_how_to_get_some(): void
    {
        $this->actingAs(User::factory()->create())->get(route('match-analysis'))
            ->assertOk()->assertSee('No analysed games yet');
    }

    public function test_it_reads_wins_against_losses_with_a_takeaway_for_the_healer(): void
    {
        $user = User::factory()->create();
        $this->storeGame($user, 'm1', '2026-09-26 19:26:00', true);
        $this->storeGame($user, 'm2', '2026-09-26 19:40:00', true);
        $this->storeGame($user, 'm3', '2026-09-26 19:50:00', false);

        $s = app(MatchAnalysisService::class)->sessions($user)[0];
        $a = app(MatchAnalysisService::class)->build($user, $s['date'], $s['bracket'], $s['team']);

        $this->assertSame(['won' => 2, 'lost' => 1], $a['record']);
        $kills = collect($a['comparisons'])->firstWhere('label', 'Your goes that led to a kill (in the go or 30s after)');
        $this->assertSame([50.0, 0.0], [(float) $kills['left']['value'], (float) $kills['right']['value']]);
        $this->assertTrue($kills['lead'], 'three games is a lead, never a finding');
        $this->assertStringContainsString('locked out at that moment in 1 of 1 losses', $a['takeaways'][0]);
        $this->assertStringContainsString('Medallion had already been used earlier in 1', $a['takeaways'][0]);
        $this->assertSame(4, $a['pendingExperience'], 'experience not yet looked up is reported, not guessed');
        $this->assertSame('pending', $a['games'][0]['level']);
    }

    public function test_experience_that_is_cached_fills_the_level(): void
    {
        $user = User::factory()->create();
        $this->storeGame($user, 'm1', '2026-09-26 19:26:00', true);
        foreach (['Healz', 'Dk', 'Druid', 'Mage'] as $n) {
            Cache::put('player_experience:v1:'.md5(mb_strtolower("{$n}-Realm-US")), ['found' => true, 'exp3v3' => 2400, 'gladSeasons' => 2, 'rankOneSeasons' => 0, 'legendSeasons' => 0, 'bestRank' => 'Gladiator'], 60);
        }

        $s = app(MatchAnalysisService::class)->sessions($user)[0];
        $a = app(MatchAnalysisService::class)->build($user, $s['date'], $s['bracket'], $s['team']);

        $this->assertSame('Gladiator', $a['games'][0]['level']);
        $this->assertSame(0, $a['pendingExperience']);
        $this->assertSame('2x Glad 2400', $a['games'][0]['enemies'][0]['xp']);
    }

    public function test_one_player_never_sees_anothers_games(): void
    {
        $owner = User::factory()->create();
        $this->storeGame($owner, 'm1', '2026-09-26 19:26:00', true);

        $this->actingAs(User::factory()->create())->get(route('match-analysis'))
            ->assertOk()->assertSee('No analysed games yet')->assertDontSee('Restoration Druid');

        $this->actingAs($owner)->get(route('match-analysis'))
            ->assertOk()->assertSee('Who you played')->assertSee('Restoration Druid');
    }

    public function test_the_patterns_are_drawn_as_abilities(): void
    {
        $user = User::factory()->create();
        $this->storeGame($user, 'm1', '2026-09-26 19:26:00', true);
        $this->storeGame($user, 'm2', '2026-09-26 19:50:00', false);

        $this->actingAs($user)->get(route('match-analysis'))
            ->assertOk()
            ->assertSee('Your goes that killed')
            ->assertSee('What answered your goes')
            ->assertSee('Ironbark')
            ->assertSee('on their healer');
    }

    public function test_a_game_opens_as_its_goes_for_its_owner_only(): void
    {
        $owner = User::factory()->create();
        $this->storeGame($owner, 'm1', '2026-09-26 19:26:00', true);
        $id = ArenaRound::where('match_id', 'm1')->value('id');

        $game = app(MatchAnalysisService::class)->game($owner, $id);
        $this->assertCount(2, $game['goes']);
        $this->assertSame('Psychic Scream', $game['goes'][0]['links'][0]['spell']);
        $this->assertSame('healer', $game['goes'][0]['links'][0]['role']);

        $this->assertNull(app(MatchAnalysisService::class)->game(User::factory()->create(), $id), 'another player cannot open it');

        \Livewire\Livewire::actingAs($owner)->test(\App\Livewire\MatchAnalysis::class)
            ->call('openGame', $id)
            ->assertSee('Your go')
            ->assertSee('Forced from them')
            ->assertSee('Back to the session');
    }

    public function test_a_game_analysed_before_goes_were_stored_in_full_says_to_upload_it_again(): void
    {
        $user = User::factory()->create();
        $this->storeGame($user, 'm1', '2026-09-26 19:26:00', true, ['goes' => [[
            'side' => 'us', 'from' => 10, 'to' => 30, 'good' => true, 'chain' => 'Army of the Dead', 'healerCc' => 0,
            'defs' => 1, 'defNames' => [], 'drained' => 0, 'kill' => null, 'killLater' => false,
            'peak' => ['damage' => 1, 'joint' => false, 'healerLocked' => 0.0, 'healerKicked' => false, 'abilities' => []],
            'ownHealerLockedAtCds' => false,
        ]]]);
        $id = ArenaRound::where('match_id', 'm1')->value('id');

        $this->assertTrue(app(MatchAnalysisService::class)->game($user, $id)['outdated']);
    }
}
