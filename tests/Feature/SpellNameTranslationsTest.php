<?php

namespace Tests\Feature;

use App\Http\Services\SpellIconIndex;
use App\Http\Services\SpellNameTranslations;
use App\Http\Services\WatchPageService;
use App\Models\ArenaRound;
use App\Models\Game;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Chinese client logs 激流 where an English one logs Riptide (2026-10-27, docs/international.md).
 * Before this, a Chinese game's casts drew no icons and its arena frames never showed a button used,
 * because both look spells up by the English name.
 */
class SpellNameTranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $game = Game::create(['name' => 'World of Warcraft', 'slug' => 'wow']);
        $patch = Patch::create(['game_id' => $game->id, 'build_version' => '1.0.0', 'is_current' => true]);
        Spell::create(['patch_id' => $patch->id, 'spell_id' => 61295, 'name' => 'Riptide', 'icon_name' => 'spell_nature_riptide.jpg']);
        Spell::create(['patch_id' => $patch->id, 'spell_id' => 408, 'name' => 'Kidney Shot', 'icon_name' => 'ability_rogue_kidneyshot.jpg']);
    }

    public function test_a_round_teaches_its_own_names_and_nothing_else(): void
    {
        $raw = implode("\n", [
            '10/26/2026 19:11:02.123-4  SPELL_CAST_SUCCESS,Player-4920-0A1B2C3D,"萨满-死亡之翼-CN",0x511,0x80000000,Player-4920-0A1B2C3D,"萨满-死亡之翼-CN",0x511,0x80000000,61295,"激流",0x8,Player-4920-0A1B2C3D',
            '10/26/2026 19:11:03.000-4  SPELL_AURA_APPLIED,Player-4920-0E0F,"盗贼-死亡之翼-CN",0x548,0x80000000,Player-4920-0A1B2C3D,"萨满-死亡之翼-CN",0x511,0x80000000,408,"肾击",0x1,DEBUFF',
            '10/26/2026 19:11:04.000-4  SPELL_CAST_SUCCESS,Player-1-2,"Nearó-Barthilas-US",0x511,0x80000000,0000000000000000,nil,0x80000000,0x80000000,61295,"Riptide",0x8,Player-1-2',
            '10/26/2026 19:11:05.000-4  SPELL_CAST_SUCCESS,Player-4920-0E0F,"盗贼-死亡之翼-CN",0x548,0x80000000,0000000000000000,nil,0x80000000,0x80000000,999999,"不在数据里",0x1,Player-4920-0E0F',
        ]);

        $translations = app(SpellNameTranslations::class);
        $this->assertSame(2, $translations->learn($raw));
        $this->assertSame(0, app(SpellNameTranslations::class)->learn($raw), 'learned once');

        $this->assertSame(['激流' => 'Riptide', '肾击' => 'Kidney Shot'], app(SpellNameTranslations::class)->english(['激流', '肾击', 'Riptide', '不在数据里', '萨满-死亡之翼-CN']));
    }

    public function test_a_chinese_name_draws_the_english_spells_icon(): void
    {
        app(SpellNameTranslations::class)->learn(',61295,"激流",0x8,');

        $this->assertSame('spell_nature_riptide.jpg', app(SpellIconIndex::class)->for(['激流'])['激流']?->icon_name);
        $this->assertSame('spell_nature_riptide.jpg', app(SpellIconIndex::class)->for(['Riptide'])['Riptide']?->icon_name, 'English is untouched');
    }

    public function test_the_arena_frames_count_a_chinese_press_and_name_it_in_english(): void
    {
        app(SpellNameTranslations::class)->learn(',408,"肾击",0x1,');
        $user = User::factory()->create();
        $round = ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => 'lobby-cn', 'match_id' => 'm', 'roster_key' => 'r',
            'bracket' => '3v3', 'sequence' => 1, 'played_at' => '2026-10-26 19:10:59',
            'payload' => ['analysis' => ['version' => 16, 'won' => false, 'goes' => [], 'deaths' => [],
                'players' => [['guid' => 'E-1', 'name' => '盗贼-死亡之翼-CN', 'spec' => 'Subtlety Rogue', 'classSlug' => 'rogue', 'side' => 'them',
                    'kit' => [['name' => 'Kidney Shot', 'kind' => 'control', 'cd' => 30.0, 'charges' => 1]]]],
                'timeline' => ['hp' => [], 'control' => [], 'casts' => ['E-1' => [[21.0, '肾击', null], [67.0, '肾击', null]]]]]],
        ]);

        $frame = app(WatchPageService::class)->page(collect([$round]))['rounds'][0]['frames'][0];

        $this->assertSame([21.0, 67.0], $frame['abilities'][0]['presses']);
        // Every match on the website in English (2026-10-30); before, the casts line kept the client's name.
        $this->assertSame('Kidney Shot', $frame['casts'][0][1]);
    }

    /** Spell names in English everywhere a round is read, as values and as keys; player names never. */
    public function test_a_round_reads_in_english_and_its_players_keep_their_names(): void
    {
        app(SpellNameTranslations::class)->learn(',61295,"激流",0x8, ,408,"肾击",0x1,');
        $a = app(SpellNameTranslations::class)->inEnglish([
            'players' => [['guid' => 'P-1', 'name' => '萨满-死亡之翼-CN']],
            'deaths' => [['who' => '萨满-死亡之翼-CN', 'killingBlow' => ['spell' => '肾击']]],
            'breakdown' => ['P-1' => ['healing' => ['激流' => ['amount' => 5]]]],
            'timeline' => ['casts' => ['P-1' => [[1.0, '激流', null], [2.0, 'pet: 激流', null], [3.0, '不在数据里', null]]]],
        ]);

        $this->assertSame('萨满-死亡之翼-CN', $a['players'][0]['name']);
        $this->assertSame('萨满-死亡之翼-CN', $a['deaths'][0]['who']);
        $this->assertSame('Kidney Shot', $a['deaths'][0]['killingBlow']['spell']);
        $this->assertSame(['Riptide' => ['amount' => 5]], $a['breakdown']['P-1']['healing']);
        $this->assertSame(['Riptide', 'pet: Riptide', '不在数据里'], array_column($a['timeline']['casts']['P-1'], 1), 'a name not known yet stays as logged');
    }

    public function test_a_hand_entry_wins_and_the_report_lists_only_what_is_left(): void
    {
        SpellNameTranslations::$handPath = tempnam(sys_get_temp_dir(), 'names');
        file_put_contents(SpellNameTranslations::$handPath, "# a comment\n法术封锁|Spell Lock|19647\n激流|Hand Riptide|61295\n");
        app(SpellNameTranslations::class)->learn(',61295,"激流",0x8,');
        $user = User::factory()->create();
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => 'l', 'match_id' => 'm', 'roster_key' => 'r', 'bracket' => '3v3', 'sequence' => 1,
            'payload' => ['analysis' => ['players' => [['guid' => 'P-1', 'spec' => 'Destruction Warlock'], ['guid' => 'P-2', 'spec' => 'Restoration Shaman']],
                'timeline' => ['control' => [], 'casts' => [
                    'P-1' => [[1.0, '法术封锁', null], [2.0, '恶魔治疗石', null], [3.0, '恶魔治疗石', null]],
                    'P-2' => [[4.0, 'pet: 激流', null], [5.0, '激流', null]],
                ]]]],
        ]);

        $this->assertSame(['法术封锁' => 'Spell Lock', '激流' => 'Hand Riptide', 'pet: 激流' => 'pet: Hand Riptide'],
            app(SpellNameTranslations::class)->english(['法术封锁', '激流', 'pet: 激流']), 'the hand file wins over what a round taught');

        $this->artisan('wow:translate-spell-names', ['--report' => true])->assertSuccessful();
        $report = file_get_contents(storage_path(\App\Console\Commands\TranslateSpellNames::REPORT));
        $this->assertStringContainsString('| 2 | 2 | 恶魔治疗石 | Destruction Warlock 2 | `恶魔治疗石||` |', $report);
        $this->assertStringNotContainsString('法术封锁 |', $report);
        $this->assertStringNotContainsString('激流 |', $report);
        Http::assertNothingSent();

        @unlink(SpellNameTranslations::$handPath);
        SpellNameTranslations::$handPath = null;
    }

    public function test_stored_rounds_are_translated_from_blizzards_localized_names(): void
    {
        config(['services.battlenet.client_id' => 'id', 'services.battlenet.client_secret' => 'secret']);
        Http::fake([
            'oauth.battle.net/*' => Http::response(['access_token' => 't', 'expires_in' => 86400]),
            '*/data/wow/search/spell*' => Http::response(['results' => [
                ['data' => ['id' => 279414, 'name' => ['en_US' => 'Warfront: The Battle for Stromgarde', 'zh_CN' => '战争前线：激流堡之战']]],
                ['data' => ['id' => 61295, 'name' => ['en_US' => 'Riptide', 'zh_CN' => '激流']]],
            ]]),
        ]);
        $user = User::factory()->create();
        ArenaRound::create([
            'user_id' => $user->id, 'lobby_id' => 'l', 'match_id' => 'm', 'roster_key' => 'r', 'bracket' => '3v3', 'sequence' => 1,
            'payload' => ['analysis' => ['players' => [], 'timeline' => ['casts' => ['P-1' => [[3.0, '激流', null], [5.0, 'Riptide', null]]], 'control' => []]]],
        ]);

        $this->artisan('wow:translate-spell-names')->assertSuccessful();

        $this->assertSame(['激流' => 'Riptide'], app(SpellNameTranslations::class)->english(['激流']), 'the exact match, not the one containing it');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'search/spell') && str_contains(urldecode($r->url()), 'name.zh_CN=激流') && ! str_contains($r->url(), 'locale='));
    }
}
