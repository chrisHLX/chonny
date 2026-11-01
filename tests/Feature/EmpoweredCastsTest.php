<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Support\EmpoweredCasts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Empowered spells box on PvP Guides, from the committed data/empowered-casts files. */
class EmpoweredCastsTest extends TestCase
{
    use RefreshDatabase;

    private function spec(string $class, string $slug, string $name): Specialization
    {
        $game = Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft']);
        $gameClass = GameClass::create(['game_id' => $game->id, 'name' => ucfirst($class), 'slug' => $class]);

        return Specialization::create(['class_id' => $gameClass->id, 'name' => $name, 'slug' => $slug]);
    }

    public function test_every_committed_file_has_what_the_box_draws(): void
    {
        $files = glob(base_path('data/empowered-casts/*/*.json'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $this->assertIsArray($data, $file);
            $this->assertContains($data['kind'], ['damage', 'healing'], $file);
            $this->assertNotEmpty($data['empowers'], $file);

            foreach ($data['empowers'] as $row) {
                $this->assertNotEmpty($row['results'], $file);
            }

            foreach ($data['ladder'] as $rung) {
                $this->assertCount(count($data['stacked']), $rung['up'], $file);
            }

            // Committed, so no player can be named in it.
            $this->assertStringNotContainsString('-US', file_get_contents($file), $file);
        }
    }

    public function test_a_spec_with_a_file_shows_its_box(): void
    {
        $this->spec('priest', 'discipline', 'Discipline');

        $this->get(route('pvp-guides', ['classSlug' => 'priest', 'specSlug' => 'discipline']))
            ->assertOk()
            ->assertSee('Empowered Spells')
            ->assertSee('Penance healing by buffs')
            ->assertSee('Harsh Discipline');
    }

    public function test_the_box_warns_once_the_game_has_been_patched_since(): void
    {
        $spec = $this->spec('priest', 'discipline', 'Discipline');
        $url = route('pvp-guides', ['classSlug' => 'priest', 'specSlug' => 'discipline']);
        $measured = json_decode(file_get_contents(base_path('data/empowered-casts/priest/discipline.json')), true)['build'];
        $patch = Patch::create(['game_id' => $spec->gameClass->game_id, 'build_version' => $measured, 'is_current' => true]);

        $this->get($url)->assertOk()->assertSee('on patch 12.1.0')->assertDontSee('patched since');

        $patch->update(['build_version' => $measured.'1']);

        $this->get($url)->assertOk()->assertSee('patched since');
    }

    public function test_a_spec_without_a_file_says_so(): void
    {
        $this->spec('warrior', 'protection', 'Protection');
        $this->assertNull(EmpoweredCasts::forSpec('warrior', 'protection'));

        $this->get(route('pvp-guides', ['classSlug' => 'warrior', 'specSlug' => 'protection']))
            ->assertOk()
            ->assertSee('Empowered Spells')
            ->assertSee('None confirmed');
    }
}
