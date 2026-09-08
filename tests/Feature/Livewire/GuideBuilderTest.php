<?php

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Http\Services\CcChainBuilder;
use App\Livewire\Guides\Builder;
use App\Livewire\Guides\Index;
use App\Livewire\Guides\Show;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * The guide authoring canvas, the shared read view, and the DR annotation behind them.
 *
 * Palette contents and the control/frequency metrics need a real imported spell kit, which
 * RefreshDatabase cannot give (spells only ever come from import:spelldata, never a seeder), so
 * those are verified against the live database instead. What IS asserted here is everything that
 * does not depend on imported data: ownership, access control, section layout, and the DR maths.
 */
function guideTestPatch(): Patch
{
    return Patch::firstOrCreate(
        ['build_version' => '12.0.0-guide-test'],
        ['game_id' => Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft'])->id, 'is_current' => true],
    );
}

function guideTestSpec(string $name = 'Subtlety', string $slug = 'subtlety'): Specialization
{
    $class = GameClass::firstOrCreate(
        ['slug' => 'rogue'],
        ['game_id' => Game::firstOrCreate(['slug' => 'wow'], ['name' => 'World of Warcraft'])->id, 'name' => 'Rogue'],
    );

    return Specialization::firstOrCreate(
        ['class_id' => $class->id, 'slug' => $slug],
        ['name' => $name, 'external_spec_id' => crc32($slug) % 100000],
    );
}

function makeGuideCcSpell(string $name, ?string $drCategory, int $externalId): Spell
{
    return Spell::create([
        'patch_id' => guideTestPatch()->id,
        'spell_id' => $externalId,
        'name' => $name,
        'dr_category' => $drCategory,
        'cast_type' => 'instant',
    ]);
}

function makeChainGuide(User $user): UserGuide
{
    return UserGuide::create(['user_id' => $user->id, 'title' => 'Test guide']);
}

function addSection(UserGuide $guide, UserGuideSectionKind $kind = UserGuideSectionKind::Sequence, int $row = 0, int $column = 0): UserGuideSection
{
    return UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => $kind,
        'title' => $kind->label(),
        'row' => $row,
        'column' => $column,
    ]);
}

function addBlock(UserGuideSection $section, int $position, int $externalSpellId = 408): UserGuideBlock
{
    return UserGuideBlock::create([
        'user_guide_section_id' => $section->id,
        'position' => $position,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => $externalSpellId],
    ]);
}

/*
 * Real HTTP requests, not just Livewire::test() — that renders the component in isolation and so
 * cannot catch a broken layout, a bad route helper in the nav, or a guest reaching a private page.
 */

test('both guide pages render over HTTP for their author', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    addSection($guide);

    $this->actingAs($user)->get(route('guides.index'))->assertOk()->assertSee('My Guides');
    $this->actingAs($user)->get(route('guides.edit', $guide->slug))->assertOk()->assertSee('The comp');
});

test('a guest is sent to login rather than into the builder', function () {
    $guide = makeChainGuide(User::factory()->create());

    $this->get(route('guides.index'))->assertRedirect(route('login'));
    $this->get(route('guides.edit', $guide->slug))->assertRedirect(route('login'));
});

test('a guide is addressed by slug, and an id in the URL is a 404', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);

    $this->actingAs($user)->get('/guides/'.$guide->id.'/edit')->assertNotFound();
    $this->actingAs($user)->get('/guides/'.$guide->slug.'/edit')->assertOk();
});

test('the builder refuses a guide the viewer does not own', function () {
    $guide = makeChainGuide(User::factory()->create());

    Livewire::actingAs(User::factory()->create())
        ->test(Builder::class, ['guide' => $guide])
        ->assertStatus(403);
});

/*
 * Sections.
 */

test('sections are added, renamed, reordered as whole rows, and deleted', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);

    $c->call('addSection', 'sequence')->call('addSection', 'sequence')->call('addSection', 'text');
    expect($guide->sections()->pluck('row')->all())->toBe([0, 1, 2]);

    $go = $guide->sections()->where('row', 1)->first();
    $c->call('renameSection', $go->id, '  Opener go  ');
    expect($go->fresh()->title)->toBe('Opener go');

    // A VS pair is one moment in the guide, so the whole row moves together.
    $c->call('addParallelSection', 1, 'defensives');
    $c->call('moveSection', $go->id, -1);

    expect($guide->sections()->where('row', 0)->count())->toBe(2)
        ->and($guide->sections()->where('row', 0)->where('column', 1)->first()->kind)->toBe(UserGuideSectionKind::Defensives)
        ->and($guide->sections()->where('row', 1)->first()->kind)->toBe(UserGuideSectionKind::Sequence);

    $c->call('deleteSection', $go->id);
    expect($guide->sections()->count())->toBe(3);
});

test('a row never holds more than two sections', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);
    $c->call('addSection', 'sequence')
        ->call('addParallelSection', 0, 'defensives')
        ->call('addParallelSection', 0, 'text');

    expect($guide->sections()->where('row', 0)->count())->toBe(2);
});

test('deleting a section takes its steps with it', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $section = addSection($guide);
    addBlock($section, 0);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('deleteSection', $section->id);

    expect(UserGuideBlock::count())->toBe(0);
});

test('section operations cannot touch another guide', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $otherGuide = makeChainGuide($user);
    $foreign = addSection($otherGuide);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);
    $c->call('renameSection', $foreign->id, 'hijacked')
        ->call('deleteSection', $foreign->id)
        ->call('setSectionBody', $foreign->id, 'hijacked');

    $fresh = $foreign->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->title)->not->toBe('hijacked')
        ->and($fresh->body)->toBeNull();
});

test('only a text section stores a body', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $text = addSection($guide, UserGuideSectionKind::Text, row: 0);
    $chain = addSection($guide, UserGuideSectionKind::Sequence, row: 1);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);
    $c->call('setSectionBody', $text->id, '**Kill the healer**')
        ->call('setSectionBody', $chain->id, 'should be ignored');

    expect($text->fresh()->body)->toBe('**Kill the healer**')
        ->and($chain->fresh()->body)->toBeNull();
});

test('an opponent can only be set on a VS section', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $chain = addSection($guide, UserGuideSectionKind::Sequence, row: 0);
    $vs = addSection($guide, UserGuideSectionKind::Defensives, row: 1);
    $spec = guideTestSpec();

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);

    $c->call('openOpponentPicker', $chain->id)->call('setOpponent', $spec->id);
    expect($chain->fresh()->opponent_spec_id)->toBeNull();

    $c->call('openOpponentPicker', $vs->id)->call('setOpponent', $spec->id);
    expect($vs->fresh()->opponent_spec_id)->toBe($spec->id);
});

/*
 * Steps.
 */

test('reorder only ever touches blocks belonging to the section being edited', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $section = addSection($guide, row: 0);
    $otherSection = addSection($guide, UserGuideSectionKind::Sequence, row: 1);

    $a = addBlock($section, 0, 408);
    $b = addBlock($section, 1, 118);
    $foreign = addBlock($otherSection, 0, 5782);

    Livewire::actingAs($user)
        ->test(Builder::class, ['guide' => $guide])
        // The foreign id is the point: a tampered payload must not move another section's rows,
        // even one in the same guide.
        ->call('reorder', $section->id, [$b->id, $foreign->id, $a->id]);

    expect($b->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($foreign->fresh()->position)->toBe(0)
        ->and($foreign->fresh()->user_guide_section_id)->toBe($otherSection->id);
});

test('a partial reorder keeps the omitted steps rather than dropping them', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $section = addSection($guide);

    $a = addBlock($section, 0, 408);
    $b = addBlock($section, 1, 118);
    $c = addBlock($section, 2, 5782);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('reorder', $section->id, [$c->id]);

    expect($section->blocks()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($section->blocks()->count())->toBe(3);
});

test('removeBlock cannot delete a block from another guide', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $foreign = addBlock(addSection(makeChainGuide($user)), 0);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('removeBlock', $foreign->id);

    expect($foreign->fresh())->not->toBeNull();
});

test('addSpell ignores an ability the section does not offer', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $section = addSection($guide);
    $spec = guideTestSpec();
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $spec->id]);

    // A hand-crafted call must not attach an arbitrary spell id — including one that is not crowd
    // control at all, or one belonging to a spec outside the comp.
    Livewire::actingAs($user)
        ->test(Builder::class, ['guide' => $guide->fresh()])
        ->call('addSpell', $section->id, 12345, $spec->id);

    expect(UserGuideBlock::count())->toBe(0);
});

test('a note is trimmed, capped, and cleared when emptied', function () {
    $user = User::factory()->create();
    $block = addBlock(addSection(makeChainGuide($user)), 0);
    $guide = $block->section->guide;

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);

    $c->call('setNote', $block->id, '  after they trinket  ');
    expect($block->fresh()->note())->toBe('after they trinket');

    $c->call('setNote', $block->id, str_repeat('x', 400));
    expect(mb_strlen($block->fresh()->note()))->toBe(280);

    $c->call('setNote', $block->id, '   ');
    expect($block->fresh()->note())->toBeNull()
        ->and($block->fresh()->payload)->not->toHaveKey('note');
});

/*
 * The comp.
 */

test('the comp holds up to three slots, replaces on reassign, and rejects an out-of-range slot', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $rogue = guideTestSpec();
    $mage = guideTestSpec('Frost', 'frost');

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);

    $c->call('openMemberPicker', 0)->call('setMember', $rogue->id);
    $c->call('openMemberPicker', 1)->call('setMember', $mage->id);
    expect($guide->fresh()->bracket())->toBe('2v2');

    $c->call('openMemberPicker', 1)->call('setMember', $rogue->id);
    expect($guide->fresh()->members()->count())->toBe(2)
        ->and($guide->fresh()->members()->where('position', 1)->value('spec_id'))->toBe($rogue->id);

    $c->call('openMemberPicker', 3)->call('setMember', $mage->id);
    $c->call('openMemberPicker', -1)->call('setMember', $mage->id);
    expect($guide->fresh()->members()->count())->toBe(2);
});

test('removing a comp member keeps the steps already authored from it', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);
    $spec = guideTestSpec();
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $spec->id]);
    $block = addBlock(addSection($guide), 0);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('removeMember', 0);

    // Editing the roster must not silently delete somebody's authored steps.
    expect($block->fresh())->not->toBeNull();
});

/*
 * Publishing, visibility and sharing.
 */

test('a guide with no comp cannot be published', function () {
    $user = User::factory()->create();
    $guide = makeChainGuide($user);

    Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide])->call('publish');

    expect($guide->fresh()->status)->toBe(UserGuideStatus::Draft);
});

test('publishing assigns the author a username and keeps the guide private by default', function () {
    $user = User::factory()->create(['name' => 'Chris Lee', 'username' => null]);
    $guide = makeChainGuide($user);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => guideTestSpec()->id]);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide->fresh()]);
    $c->call('publish');

    expect($guide->fresh()->status)->toBe(UserGuideStatus::Published)
        // Publishing is one decision; going public is a second, separate one.
        ->and($guide->fresh()->visibility)->toBe(UserGuideVisibility::Invited)
        ->and($user->fresh()->username)->toBe('chris-lee')
        ->and($guide->fresh()->publicUrl())->toContain('/g/chris-lee/');

    $c->call('setVisibility', 'public');
    expect($guide->fresh()->visibility)->toBe(UserGuideVisibility::Public);

    $c->call('setVisibility', 'nonsense');
    expect($guide->fresh()->visibility)->toBe(UserGuideVisibility::Public);
});

test('sharing matches an existing account and refuses an unknown address', function () {
    $user = User::factory()->create();
    $friend = User::factory()->create(['email' => 'friend@example.com']);
    $guide = makeChainGuide($user);

    $c = Livewire::actingAs($user)->test(Builder::class, ['guide' => $guide]);

    $c->set('shareEmail', 'nobody@example.com')->call('shareWith');
    expect($guide->viewers()->count())->toBe(0);
    $c->assertSet('shareError', 'No MindCollector account uses that email address yet.');

    $c->set('shareEmail', 'friend@example.com')->call('shareWith');
    expect($guide->viewers()->pluck('users.id')->all())->toBe([$friend->id]);
    $c->assertSet('shareEmail', '');

    $c->call('unshare', $friend->id);
    expect($guide->viewers()->count())->toBe(0);
});

/*
 * The public read view.
 */

test('the read view is reachable at the author username and slug', function () {
    $user = User::factory()->create(['username' => 'chris']);
    $guide = UserGuide::create([
        'user_id' => $user->id, 'title' => 'Public go',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public,
    ]);

    $this->get("/g/chris/{$guide->slug}")->assertOk()->assertSee('Public go');
});

test('a guide served under the wrong username is a 404', function () {
    $user = User::factory()->create(['username' => 'chris']);
    User::factory()->create(['username' => 'someone-else']);
    $guide = UserGuide::create([
        'user_id' => $user->id, 'title' => 'Public go',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public,
    ]);

    // Without this check the slug alone would resolve and any username would serve any author's
    // guide — a broken canonical URL.
    $this->get("/g/someone-else/{$guide->slug}")->assertNotFound();
});

test('a private or draft guide 404s for anyone without access, rather than 403', function () {
    $user = User::factory()->create(['username' => 'chris']);
    $stranger = User::factory()->create();
    $invited = User::factory()->create();

    $private = UserGuide::create([
        'user_id' => $user->id, 'title' => 'Private go',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Invited,
    ]);
    $private->viewers()->attach($invited->id);

    $draft = UserGuide::create(['user_id' => $user->id, 'title' => 'Draft go', 'status' => UserGuideStatus::Draft]);

    // 403 would confirm the guide exists, leaking that this person wrote something at this URL.
    $this->get("/g/chris/{$private->slug}")->assertNotFound();
    $this->actingAs($stranger)->get("/g/chris/{$private->slug}")->assertNotFound();
    $this->actingAs($invited)->get("/g/chris/{$private->slug}")->assertOk();
    $this->actingAs($user)->get("/g/chris/{$private->slug}")->assertOk();

    $this->actingAs($stranger)->get("/g/chris/{$draft->slug}")->assertNotFound();
    $this->actingAs($user)->get("/g/chris/{$draft->slug}")->assertOk();
});

test('the read view says plainly that it is player-written', function () {
    $user = User::factory()->create(['username' => 'chris']);
    $guide = UserGuide::create([
        'user_id' => $user->id, 'title' => 'Public go',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public,
    ]);

    // The whole trust-tier separation depends on a reader being able to tell this apart from the
    // site's derived guides at a glance.
    Livewire::test(Show::class, ['username' => 'chris', 'guide' => $guide])
        ->assertSee('Player-written guide')
        ->assertSee('Written by a player, not derived from match data');
});

/*
 * Creating.
 */

test('creating a guide from the index lands on a draft with a starter section', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Index::class)->call('create', 'comp')->assertRedirect();

    $guide = UserGuide::first();
    expect($guide->user_id)->toBe($user->id)
        ->and($guide->status)->toBe(UserGuideStatus::Draft)
        ->and($guide->sections()->count())->toBe(1)
        ->and($guide->sections()->first()->kind)->toBe(UserGuideSectionKind::Sequence);
});

test('an unknown section kind creates nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Index::class)->call('create', 'nonsense');

    expect(UserGuide::count())->toBe(0);
});

test('the index only ever deletes the viewer own guides', function () {
    $user = User::factory()->create();
    $foreign = makeChainGuide(User::factory()->create());

    Livewire::actingAs($user)->test(Index::class)->call('delete', $foreign->id);

    expect($foreign->fresh())->not->toBeNull();
});

/*
 * annotateChain() — the DR maths. buildChain() reorders its input; this must not, because in a
 * user-authored chain the order is the content.
 */

test('annotateChain preserves the author order exactly, unlike buildChain', function () {
    $spells = collect([
        makeGuideCcSpell('Polymorph', 'Incapacitate', 118),
        makeGuideCcSpell('Kidney Shot', 'Stun', 408),
        makeGuideCcSpell('Fear', 'Disorient', 5782),
    ]);

    expect(collect(app(CcChainBuilder::class)->annotateChain($spells))->map(fn ($s) => $s['spell']->name)->all())
        ->toBe(['Polymorph', 'Kidney Shot', 'Fear']);

    // buildChain() would hoist the Stun to the front.
    expect(collect(app(CcChainBuilder::class)->buildChain($spells))->first()['spell']->name)->toBe('Kidney Shot');
});

test('DR applies against any earlier step in the chain, not just the one before it', function () {
    $annotated = app(CcChainBuilder::class)->annotateChain(collect([
        makeGuideCcSpell('Kidney Shot', 'Stun', 408),
        makeGuideCcSpell('Fear', 'Disorient', 5782),
        makeGuideCcSpell('Hammer of Justice', 'Stun', 853),
        makeGuideCcSpell('Intimidation', 'Stun', 19577),
    ]));

    expect($annotated[0]['dr_percentage'])->toBe(100)
        ->and($annotated[1]['dr_percentage'])->toBe(100)
        // Separated from Kidney Shot by a Disorient, and still diminished.
        ->and($annotated[2]['dr_percentage'])->toBe(50)
        ->and($annotated[2]['dr_reason'])->toContain('Kidney Shot')
        ->and($annotated[3]['dr_percentage'])->toBe(0)
        ->and($annotated[3]['dr_immune'])->toBeTrue();
});

test('a repeat of the same ability still diminishes even though no reason is given', function () {
    $kidney = makeGuideCcSpell('Kidney Shot', 'Stun', 408);
    $annotated = app(CcChainBuilder::class)->annotateChain(collect([$kidney, $kidney]));

    // dr_applied explains a collision between two DIFFERENT abilities, so it stays false here —
    // dr_percentage is the field a UI warning must read.
    expect($annotated[1]['dr_applied'])->toBeFalse()->and($annotated[1]['dr_percentage'])->toBe(50);
});

test('an uncurated spell is passed through instead of being bucketed with other uncurated ones', function () {
    $annotated = app(CcChainBuilder::class)->annotateChain(collect([
        makeGuideCcSpell('Uncurated A', null, 900),
        makeGuideCcSpell('Uncurated B', null, 901),
    ]));

    // A null dr_category would coerce to the same "" array key for both.
    expect($annotated[1]['dr_percentage'])->toBe(100)->and($annotated[1]['dr_applied'])->toBeFalse();
});

test('annotateChain on an empty chain returns nothing rather than erroring', function () {
    expect(app(CcChainBuilder::class)->annotateChain(new Collection))->toBe([]);
});
