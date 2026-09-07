<?php

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The user_guides / _members / _sections / _blocks / _viewers schema and models.
 *
 * Most of what is asserted here is a documented design decision rather than incidental behaviour:
 * per-author slug uniqueness, slug stability across a rename, block position deliberately NOT being
 * uniquely constrained while section (row, column) IS, patch_id nulling out instead of cascading,
 * the external-vs-internal spell id distinction, and status/visibility being two separate
 * questions. Each is cheap to "tidy up" into something that looks more normal and is wrong — these
 * tests are what make that fail loudly.
 */
function makeGuideFixtures(): array
{
    $game = Game::create(['slug' => 'wow', 'name' => 'World of Warcraft']);
    $patch = Patch::create(['game_id' => $game->id, 'build_version' => '12.0.7.68453', 'is_current' => true]);
    $class = GameClass::create(['game_id' => $game->id, 'name' => 'Rogue', 'slug' => 'rogue']);
    $spec = Specialization::create([
        'class_id' => $class->id, 'name' => 'Subtlety', 'slug' => 'subtlety', 'external_spec_id' => 261,
    ]);

    return ['user' => User::factory()->create(), 'patch' => $patch, 'class' => $class, 'spec' => $spec];
}

function makeGuide(User $user, string $title = 'Test guide'): UserGuide
{
    return UserGuide::create(['user_id' => $user->id, 'title' => $title]);
}

function makeSection(UserGuide $guide, UserGuideSectionKind $kind = UserGuideSectionKind::Chain, int $row = 0, int $column = 0): UserGuideSection
{
    return UserGuideSection::create([
        'user_guide_id' => $guide->id,
        'kind' => $kind,
        'title' => $kind->label(),
        'row' => $row,
        'column' => $column,
    ]);
}

test('slug generates from the title and suffixes rather than colliding', function () {
    $f = makeGuideFixtures();

    expect(makeGuide($f['user'], 'RMD Opener')->slug)->toBe('rmd-opener')
        ->and(makeGuide($f['user'], 'RMD Opener')->slug)->toBe('rmd-opener-1');
});

test('two authors may both own the same slug', function () {
    $f = makeGuideFixtures();
    $other = User::factory()->create();

    // The public URL is /g/{username}/{slug}, so the username namespaces it. A stranger taking
    // "RMD opener" must not push this author onto "rmd-opener-1".
    expect(makeGuide($f['user'], 'RMD Opener')->slug)->toBe('rmd-opener')
        ->and(makeGuide($other, 'RMD Opener')->slug)->toBe('rmd-opener');
});

test('renaming a guide does not move its public URL', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user'], 'Kidny Shot Chain');
    $original = $guide->slug;

    $guide->update(['title' => 'Kidney Shot Chain']);

    // A shared link must keep working after the author fixes a typo.
    expect($guide->fresh()->slug)->toBe($original);
});

test('status and visibility round-trip as enums but are stored as plain strings', function () {
    $f = makeGuideFixtures();
    $guide = UserGuide::create([
        'user_id' => $f['user']->id,
        'title' => 'Go Setup',
        'status' => UserGuideStatus::Published,
        'visibility' => UserGuideVisibility::Public,
    ]);

    expect($guide->fresh()->status)->toBe(UserGuideStatus::Published)
        ->and($guide->fresh()->visibility)->toBe(UserGuideVisibility::Public);

    // Plain string columns, never DB enums — extending a real enum column breaks the whole suite
    // under SQLite (see the migration docblock).
    $raw = DB::table('user_guides')->where('id', $guide->id)->first();
    expect($raw->status)->toBe('published')->and($raw->visibility)->toBe('public');
});

test('a new guide is a private draft', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user'])->fresh();

    // Publishing is one decision and going public is a second, separate one — a guide can never
    // become world-readable by accident.
    expect($guide->status)->toBe(UserGuideStatus::Draft)
        ->and($guide->visibility)->toBe(UserGuideVisibility::Invited);
});

test('blocks belong to a section and read back in author order', function () {
    $f = makeGuideFixtures();
    $section = makeSection(makeGuide($f['user']));

    foreach ([2 => 'C', 0 => 'A', 1 => 'B'] as $position => $text) {
        UserGuideBlock::create([
            'user_guide_section_id' => $section->id,
            'position' => $position,
            'block_type' => UserGuideBlockType::Note,
            'payload' => ['text' => $text],
        ]);
    }

    expect($section->blocks->map->text()->all())->toBe(['A', 'B', 'C']);
});

test('block position is not uniquely constrained, so a reorder can pass through a collision', function () {
    $f = makeGuideFixtures();
    $section = makeSection(makeGuide($f['user']));

    $a = UserGuideBlock::create(['user_guide_section_id' => $section->id, 'position' => 0, 'block_type' => UserGuideBlockType::Heading, 'payload' => ['text' => 'A']]);
    $b = UserGuideBlock::create(['user_guide_section_id' => $section->id, 'position' => 1, 'block_type' => UserGuideBlockType::Heading, 'payload' => ['text' => 'B']]);

    // Persisting a drag rewrites the run one row at a time; midway through, two rows legitimately
    // share a position. A unique index would reject this.
    $b->update(['position' => 0]);
    $a->update(['position' => 1]);

    expect($section->fresh()->blocks->map->text()->all())->toBe(['B', 'A']);
});

test('a section grid cell holds exactly one section', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user']);

    makeSection($guide, row: 0, column: 0);
    makeSection($guide, UserGuideSectionKind::Defensives, row: 0, column: 1);

    // Unlike block position, a section is placed rather than dragged through transient duplicates,
    // so this constraint is safe and worth having.
    expect(fn () => makeSection($guide, row: 0, column: 1))->toThrow(QueryException::class);
});

test('a spell block exposes the external spell id and other block types never do', function () {
    $f = makeGuideFixtures();
    $section = makeSection(makeGuide($f['user']));

    // 408 is Blizzard's own id for Kidney Shot — NOT a spells.id, which is an internal
    // auto-increment key and is reassigned on a patch bump.
    $spell = UserGuideBlock::create([
        'user_guide_section_id' => $section->id, 'position' => 0,
        'block_type' => UserGuideBlockType::Spell,
        'payload' => ['external_spell_id' => 408, 'note' => 'only from stealth'],
    ]);
    $note = UserGuideBlock::create([
        'user_guide_section_id' => $section->id, 'position' => 1,
        'block_type' => UserGuideBlockType::Note, 'payload' => ['text' => 'Hold for the trap.'],
    ]);

    expect($spell->externalSpellId())->toBe(408)
        ->and($spell->note())->toBe('only from stealth')
        ->and($note->externalSpellId())->toBeNull()
        ->and($note->text())->toBe('Hold for the trap.');
});

test('deleting a guide takes its sections, blocks, comp and viewers with it', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user']);
    $section = makeSection($guide);

    UserGuideBlock::create(['user_guide_section_id' => $section->id, 'position' => 0, 'block_type' => UserGuideBlockType::Note, 'payload' => ['text' => 'x']]);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $f['spec']->id]);
    $guide->viewers()->attach(User::factory()->create()->id);

    $guide->delete();

    expect(UserGuideSection::count())->toBe(0)
        ->and(UserGuideBlock::count())->toBe(0)
        ->and(UserGuideMember::count())->toBe(0)
        ->and(DB::table('user_guide_viewers')->count())->toBe(0);
});

test('a guide outlives the patch it was written against', function () {
    $f = makeGuideFixtures();
    $guide = UserGuide::create([
        'user_id' => $f['user']->id, 'title' => 'Survivor', 'patch_id' => $f['patch']->id,
    ]);

    // Deliberately unlike talent_builds, which cascade-deletes with its patch. Blocks resolve
    // abilities through Blizzard's external id, so a guide is still readable afterwards.
    $f['patch']->delete();

    expect($guide->fresh())->not->toBeNull()
        ->and($guide->fresh()->patch_id)->toBeNull()
        ->and($guide->fresh()->title)->toBe('Survivor');
});

test('a roster is required before a guide has anything to draw from, and the bracket is derived', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user']);
    $other = Specialization::create(['class_id' => $f['class']->id, 'name' => 'Assassination', 'slug' => 'assassination', 'external_spec_id' => 259]);

    expect($guide->hasRoster())->toBeFalse()->and($guide->bracket())->toBeNull();

    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $f['spec']->id]);
    // One spec is a valid saved state but not yet a comp, so no bracket is claimed.
    expect($guide->hasRoster())->toBeTrue()->and($guide->bracket())->toBeNull();

    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 1, 'spec_id' => $other->id]);
    expect($guide->bracket())->toBe('2v2');
});

test('the same spec may fill two comp slots, but one slot never holds two specs', function () {
    $f = makeGuideFixtures();
    $guide = makeGuide($f['user']);

    // A real 3v3 can run two of the same spec, so this must be allowed.
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $f['spec']->id]);
    UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 1, 'spec_id' => $f['spec']->id]);

    expect($guide->bracket())->toBe('2v2');
    expect(fn () => UserGuideMember::create(['user_guide_id' => $guide->id, 'position' => 0, 'spec_id' => $f['spec']->id]))
        ->toThrow(QueryException::class);
});

/*
 * Access control. Three gates — author, status, visibility — and every combination that could leak
 * a guide is asserted rather than reasoned about.
 */

test('a draft is readable only by its author, whatever its visibility says', function () {
    $f = makeGuideFixtures();
    $stranger = User::factory()->create();

    $guide = UserGuide::create([
        'user_id' => $f['user']->id, 'title' => 'Draft',
        'status' => UserGuideStatus::Draft, 'visibility' => UserGuideVisibility::Public,
    ]);

    expect($guide->isReadableBy($f['user']))->toBeTrue()
        ->and($guide->isReadableBy($stranger))->toBeFalse()
        ->and($guide->isReadableBy(null))->toBeFalse();
});

test('a published public guide is readable by anyone, including signed-out visitors', function () {
    $f = makeGuideFixtures();

    $guide = UserGuide::create([
        'user_id' => $f['user']->id, 'title' => 'Public',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public,
    ]);

    expect($guide->isReadableBy(null))->toBeTrue()
        ->and($guide->isReadableBy(User::factory()->create()))->toBeTrue();
});

test('a published private guide is readable only by invited accounts', function () {
    $f = makeGuideFixtures();
    $invited = User::factory()->create();
    $stranger = User::factory()->create();

    $guide = UserGuide::create([
        'user_id' => $f['user']->id, 'title' => 'Private',
        'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Invited,
    ]);
    $guide->viewers()->attach($invited->id);

    expect($guide->isReadableBy($invited))->toBeTrue()
        ->and($guide->isReadableBy($stranger))->toBeFalse()
        ->and($guide->isReadableBy(null))->toBeFalse()
        ->and($guide->isReadableBy($f['user']))->toBeTrue();
});

test('only published public guides are listed', function () {
    $f = makeGuideFixtures();

    UserGuide::create(['user_id' => $f['user']->id, 'title' => 'Listed', 'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Public]);
    UserGuide::create(['user_id' => $f['user']->id, 'title' => 'Private', 'status' => UserGuideStatus::Published, 'visibility' => UserGuideVisibility::Invited]);
    UserGuide::create(['user_id' => $f['user']->id, 'title' => 'Draft', 'status' => UserGuideStatus::Draft, 'visibility' => UserGuideVisibility::Public]);

    expect(UserGuide::listed()->pluck('title')->all())->toBe(['Listed']);
});

/*
 * Markdown. This is the one place a user's raw input is rendered as HTML for other people.
 */

test('section markdown renders but strips raw HTML and unsafe links', function () {
    $f = makeGuideFixtures();
    $section = makeSection(makeGuide($f['user']), UserGuideSectionKind::Text);

    $section->update(['body' => "**Bold** text\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))"]);

    $html = $section->fresh()->bodyHtml();

    expect($html)->toContain('<strong>Bold</strong>')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('javascript:');
});

test('a sequence section never renders a body even if one somehow exists', function () {
    $f = makeGuideFixtures();
    $section = makeSection(makeGuide($f['user']), UserGuideSectionKind::Chain);
    $section->update(['body' => '**should not render**']);

    expect($section->fresh()->bodyHtml())->toBe('');
});

test('a user reaches their own guides and the ones shared with them separately', function () {
    $f = makeGuideFixtures();
    $friend = User::factory()->create();

    makeGuide($f['user'], 'Mine');
    $theirs = makeGuide($friend, 'Theirs');
    $theirs->viewers()->attach($f['user']->id);

    expect($f['user']->guides()->pluck('title')->all())->toBe(['Mine'])
        ->and($f['user']->sharedGuides()->pluck('title')->all())->toBe(['Theirs']);
});

test('a username is assigned on demand and is unique', function () {
    $a = User::factory()->create(['name' => 'Chris Lee', 'username' => null]);
    $b = User::factory()->create(['name' => 'Chris Lee', 'username' => null]);

    expect($a->resolveUsername())->toBe('chris-lee')
        ->and($b->resolveUsername())->toBe('chris-lee-1');

    // Stable once assigned — the public URL is built from it.
    expect($a->fresh()->resolveUsername())->toBe('chris-lee');
});
