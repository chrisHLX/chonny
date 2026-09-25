<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModuleQuizController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProficiencyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\StripeWebhookController;
use App\Livewire\Admin\ApiUsage;
use App\Livewire\Admin\ContentManager;
use App\Livewire\Admin\DiagnosticStats;
use App\Livewire\Admin\GameDataBrowser;
use App\Livewire\Admin\LogViewer;
use App\Livewire\Admin\PageUsage;
use App\Livewire\Admin\TalentBuildEditor;
use App\Livewire\Admin\WeakAreas;
use App\Livewire\JobDashboard;
use App\Livewire\Modules\Building;
use App\Livewire\Modules\Index;
use App\Livewire\Modules\Show;
use App\Livewire\QuizPage;
use App\Livewire\SpellExplorer;
use App\Models\Module;
use App\Models\Question;
use Illuminate\Support\Facades\Route;

// STRIPE
Route::post('/checkout/session', [StripeController::class, 'create'])
    ->middleware('auth')
    ->name('checkout.session');

Route::post('/webhook/stripe', [StripeWebhookController::class, 'handle']);

// Optional redirect pages
Route::get('/checkout/success', fn () => view('checkout.success'))->name('checkout.success');
Route::get('/checkout/cancel', fn () => view('checkout.cancel'))->name('checkout.cancel');

// The public front page (2026-09-16): what the site is for, the comp builder as the first action,
// and the public feed of game plans. Signed-in players are sent on to Home. See App\Livewire\Landing.
//
// This used to be a 301 to /wow/comps. Browsers cache a 301, so a visitor who hit the old redirect
// may keep being sent to the comp builder by their own browser until that cache clears — nothing
// server-side can undo it, and it is harmless (the builder still works).
Route::get('/', \App\Livewire\Landing::class)->name('home');

Route::get('/diagnostic', function () {
    return view('diagnostic');
})->name('diagnostic');

Route::get('/terms', function () {
    return view('terms');
})->name('terms');

Route::get('/privacy', function () {
    return view('privacy');
})->name('privacy');

// No 'verified' middleware, deliberately (2026-09-14). It used to sit on this group, so on
// production (User::hasVerifiedEmail() only bypasses the check outside production) every email
// sign-up's first screen was a "please verify" wall — while the guide builder, the thing Home
// exists to promote, never required verification at all. An unconfirmed account now gets in and
// sees a "confirm your email" banner (layouts.app) instead. The email still matters for password
// reset, which is why the banner stays until it is confirmed.
Route::middleware('auth')->group(function () {
    // Where every sign-in lands: the arena side of the site (guides, comps, friends). The route
    // keeps its old name and path so the many redirects and bookmarks to it still work.
    Route::get('/dashboard', \App\Livewire\Home::class)->name('dashboard');

    // The learning profile that used to BE the dashboard — diagnostic, concept mastery, quizzes.
    // Unchanged, one link away from Home. See App\Livewire\Home's docblock.
    Route::get('/training', [DashboardController::class, 'index'])->name('training');

    Route::get('/friends', \App\Livewire\Friends::class)->name('friends.index');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/jobs', JobDashboard::class)->name('jobs.dashboard');
});

Route::post('/credits', [AiController::class, 'test'])->name('credit.test');
Route::post('/creditsTest2', [AiController::class, 'test2'])->name('credit.test2');

// routes/web.php or api.php
Route::get('/pipelines/{pipeline}', [PipelineController::class, 'status']);

Route::get('/next-module/{pipeline}', [PipelineController::class, 'nextModule'])
    ->name('pipelines.next-module');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/handle', [ProfileController::class, 'updateUsername'])->name('profile.username');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/concepts', [ConceptController::class, 'index'])->name('concepts.index');

Route::get('/concepts/create', [ConceptController::class, 'create'])->name('concepts.create');
Route::post('/concepts', [ConceptController::class, 'store'])->name('concepts.store');

// questions
Route::get('/questions/quiz', QuizPage::class)->name('questions.quiz.index')->middleware('auth');
// Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy')->middleware('auth');
// Route::get('/questions/quiz', [QuestionController::class, 'quiz'])->name('questions.quiz.index')->middleware('auth');
Route::post('/questions/{question}/answer', [QuestionController::class, 'submit'])->name('questions.answer');
Route::post('/questions', [QuestionController::class, 'store'])->name('questions.store');

// create and store modules
Route::get('modules', Index::class)->name('modules.index');
// Route::get('modules', [ModuleController::class, 'index'])->name('modules.index')->middleware('auth');

// ------- Game-scoped pages: /wow/... -------
//
// EVERY page whose content only makes sense for one game lives under that game's own segment, so
// a second game is a sibling prefix rather than a rename of nine live URLs. Until 2026-09-16 these
// sat at the site root (/spells, /wow-comps, /burst-guides, ...), which is only unambiguous while
// exactly one game exists — `games` is already a real table and `classes`/`patches` are already
// scoped to it, so the schema was ready for a second game and the URLs were not.
//
// Done BEFORE the site is advertised on purpose. Every one of these paths keeps working (see the
// permanent redirects below), but a 301 is a tax on every future visitor who follows an old link,
// and the cheapest moment to stop minting old links is before anyone else has any.
//
// ROUTE NAMES ARE UNCHANGED, deliberately: every internal link is built with route(), so moving
// the paths touches no view. The three places that hardcoded a URL (the JSON-LD block in
// layouts/app.blade.php and public/sitemap.xml) were updated with this change.
//
// The game segment is a literal, not a {game} parameter. A parameter would imply these components
// can render another game, and none of them can — SpellExplorer, WowComps and the rest are built
// on WoW's own spec/talent/spell model throughout. When a second game arrives it gets its own
// group and its own components; this stays honest about what it is until then.
Route::prefix('wow')->group(function () {
    Route::get('/comps', \App\Livewire\WowComps::class)->name('wow-comps');

    // Two comps on one clock — whose kill window opens first, and why. The first page here that
    // answers a question about a MATCHUP rather than about one spec or one comp, which is the
    // unit a guide is actually written for. See App\Livewire\MatchupLab and
    // data/matchup-profiles/README.md. Sits next to /wow/comps deliberately: same picker, same
    // mental model, one step further on.
    Route::get('/matchup-lab', \App\Livewire\MatchupLab::class)->name('matchup-lab');

    // One played game read back from its own combat log — rounds, per-player output, and the
    // same-spec mirror comparison. See App\Livewire\GameReview.
    //
    // AUTH, AND SCOPED TO THE VIEWER'S OWN GAMES. This is a signed-in player's record of their
    // own matches, not a public browser: a review names five other players with their talents and
    // their gear. It shipped public for about twenty minutes on 2026-09-25 and was closed as soon
    // as that was noticed. Do not remove this middleware — the component scopes by
    // auth()->id() as well, and both halves are meant to be there.
    Route::get('/game-review/{id?}', \App\Livewire\GameReview::class)
        ->middleware('auth')
        ->name('game-review');

    // The upload the page's button drives. One arena round per request, gzipped by the browser —
    // a whole combat log cannot be posted (nginx is on its 1MB default here and PHP allows 2MB),
    // and the client only ever finds round boundaries; all parsing is server side. See
    // App\Http\Services\ArenaReviewIngestService.
    Route::post('/game-review/upload-round', [\App\Http\Controllers\ArenaUploadController::class, 'round'])
        ->middleware('auth')
        ->name('game-review.upload-round');

    // Called once after a batch of rounds, so a lobby's six are assembled together rather than
    // six times over.
    Route::post('/game-review/assemble', [\App\Http\Controllers\ArenaUploadController::class, 'assemble'])
        ->middleware('auth')
        ->name('game-review.assemble');
    Route::get('/spells', SpellExplorer::class)->name('spells.explore');
    Route::get('/spell-finder', \App\Livewire\SpellFinder::class)->name('spell-finder');
    // One permanent, linkable page per spell. Renders the same <x-spells.detail> the site-wide
    // modal does, from the same SpellProfile — see App\Livewire\SpellDetail.
    Route::get('/spell/{spellId}', \App\Livewire\SpellDetail::class)->whereNumber('spellId')->name('spell.show');
    Route::get('/spell-counters', \App\Livewire\ClaudesCounters::class)->name('claudes-counters');

    Route::get('/top-damage-rotations', \App\Livewire\TopDamageRotations::class)->name('top-damage-rotations');
    Route::get('/top-damage-rotations/{classSlug}/{specSlug}/{length}/talents', \App\Livewire\BurstWindowTalents::class)
        ->where('length', '[0-9]+')
        ->name('burst-window-talents');

    Route::get('/class-guide/{classSlug?}/{specSlug?}', \App\Livewire\ClassGuide::class)->name('class-guide');
    Route::get('/cc-chains', \App\Livewire\TopCcChains::class)->name('top-cc-chains');
    Route::get('/claudes-guides/{classSlug?}/{specSlug?}', \App\Livewire\ClaudesGuides::class)->name('claudes-guides');
    Route::get('/burst-guides', \App\Livewire\BurstGuides::class)->name('burst-guides');

    // The combined per-spec view over the four routes above it (class-guide, burst-guides, spells,
    // spell-counters). Those four are deliberately NOT redirected here: each still renders
    // standalone, and each is what an existing bookmark or the sitemap resolves to. See
    // App\Livewire\PvpGuides. `?tab=` selects the panel; spec lives in the path so a guide link
    // always names the spec it opens on.
    Route::get('/pvp-guides/{classSlug?}/{specSlug?}', \App\Livewire\PvpGuides::class)->name('pvp-guides');

    // Class quizzes (Training). Questions are built from live game data each attempt, so they stay
    // current when a patch changes the kit. The engine is game-neutral (App\Quiz); these routes and
    // their components are WoW's. See App\Quiz\QuizService.
    Route::get('/quiz/{classSlug?}/{specSlug?}', \App\Livewire\Quizzes\WowQuizIndex::class)->name('wow-quiz');
    Route::get('/quiz/{classSlug}/{specSlug}/level/{level}', \App\Livewire\Quizzes\WowQuizPlay::class)
        ->whereNumber('level')
        ->name('wow-quiz.play');

    // Concept drills: the same generated questions, selected by a learning-platform concept
    // instead of by a level. The spec stays in the path because it flavours the questions — the
    // concept is what the answers are scored against. See App\Learning\ConceptCoverage.
    Route::get('/quiz/{classSlug}/{specSlug}/drill/{conceptSlug}', \App\Livewire\Quizzes\ConceptDrillPlay::class)
        ->name('wow-quiz.drill');

    // Curation review tools. Not linked from the nav, but real URLs people have open.
    Route::get('/cc-review', \App\Livewire\CcReview::class)->name('cc-review');
    Route::get('/cc-immunity-review', \App\Livewire\CcImmunityReview::class)->name('cc-immunity-review');
});

// Fire-and-forget usage beacon for WoW Comps' Alpine-only tab bar — see TrackController. NOT moved
// under /wow: it is an internal endpoint the page posts to, not a page anyone links to, and moving
// it would break any tab already open across the deploy for no benefit.
Route::post('/track/wow-comps-tab', [\App\Http\Controllers\TrackController::class, 'wowCompsTab'])
    ->name('track.wow-comps-tab');

// ------- Old paths, kept working permanently -------
//
// One 301 per old page, each also forwarding anything after it ({rest}), which is what keeps the
// parameterised pages whole: /class-guide/priest/discipline and
// /top-damage-rotations/rogue/subtlety/15/talents land on their new equivalents rather than on a
// bare index. 301 rather than 302 so search engines move their index across instead of holding
// both. These are cheap to keep and must not be removed — a link on Reddit, in a Discord, or in
// somebody's bookmarks outlives any of our opinions about tidiness.
// Written as an explicit old => new map rather than by gluing '/wow' onto the old path, because
// one page changed its NAME as well as its place: /wow-comps became /wow/comps, not
// /wow/wow-comps, which would have been a 404 served to every visitor following the single
// most-linked URL on the site.
$movedToWow = [
    '/wow-comps' => '/wow/comps',
    '/spells' => '/wow/spells',
    '/spell-finder' => '/wow/spell-finder',
    '/spell' => '/wow/spell',
    '/spell-counters' => '/wow/spell-counters',
    '/top-damage-rotations' => '/wow/top-damage-rotations',
    '/class-guide' => '/wow/class-guide',
    '/cc-chains' => '/wow/cc-chains',
    '/claudes-guides' => '/wow/claudes-guides',
    '/burst-guides' => '/wow/burst-guides',
    '/pvp-guides' => '/wow/pvp-guides',
    '/cc-review' => '/wow/cc-review',
    '/cc-immunity-review' => '/wow/cc-immunity-review',
];

foreach ($movedToWow as $old => $new) {
    Route::get($old.'/{rest?}', fn (?string $rest = null) => redirect($new.($rest !== null ? '/'.$rest : ''), 301))
        ->where('rest', '.*');
}

// Older still: /claudes-counters was renamed to /spell-counters long before the /wow move, and is
// now two hops from home. Sent straight to the current URL rather than through the hop above.
Route::get('/claudes-counters', fn () => redirect('/wow/spell-counters', 301));

Route::get('/modules/manage', [ModuleController::class, 'manage'])->name('modules.manage')->middleware('auth');
Route::get('/modules/create', [ModuleController::class, 'create'])->name('modules.create')->middleware('auth');
Route::get('/modules/upload', [\App\Http\Controllers\ModuleUploadController::class, 'create'])->name('modules.upload')->middleware('auth');
Route::post('/modules/upload', [\App\Http\Controllers\ModuleUploadController::class, 'store'])->name('modules.upload.store')->middleware('auth');
Route::post('/modules-pagex/createLandingPage/{module}', [ModuleController::class, 'createLandingPage'])->name('modules-pagex.createLandingPage')->middleware('auth');
Route::post('/modules/{module}/generate-questions', [ModuleController::class, 'generateQuestions'])
    ->name('modules.generate-questions')
    ->middleware('auth');
Route::post('/modules/{module}/research', [ModuleController::class, 'research'])
    ->name('modules.research')
    ->middleware('auth');
Route::post('/modules/{module}/synthesise', [ModuleController::class, 'synthesise'])
    ->name('modules.synthesise')
    ->middleware('auth');
Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store')->middleware('auth');
Route::delete('/modules/destroy/{module}', [ModuleController::class, 'destroy'])->name('modules.destroy')->middleware('auth');
Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('modules.edit')->middleware('auth');
Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('modules.update')->middleware('auth');
Route::get('/modules/{module}/export', [ModuleController::class, 'export'])->name('modules.export')->middleware('auth');
Route::post('/modules/explore', [ModuleController::class, 'explore'])->name('modules.explore')->middleware('auth');
Route::get('/modules/{module}/building', Building::class)->name('modules.building')->middleware('auth');

// Routes can be very temperamental, so we need to create unique routes for each action for example
// Dont use the same route for both destroy and destroyPage, even if they are similar
// Thats why we changed the destroyPage route to be more specific
// Module Pages
Route::delete('/module-page/{modulePage}', [ModuleController::class, 'destroyPage'])
    ->name('module-page.destroyPage')
    ->middleware('auth');

Route::post('/modules/{module}/pages/save', [ModuleController::class, 'savePage'])
    ->name('module-pages.save')
    ->middleware('auth');

// Module detail page — public, no auth required
Route::get('/modules/{module}', Show::class)->name('modules.show');

Route::get('/modules/{module}/quiz', [ModuleQuizController::class, 'show'])->name('modules.quiz');
Route::post('/modules/{module}/start', [ModuleQuizController::class, 'start'])->name('modules.start')->middleware('auth');

Route::post('/modules/{module}/assign', [ModuleController::class, 'assign'])->name('modules.assign')->middleware('auth');

// Ai Requests Page
Route::get('/ai_requests', [AiController::class, 'index'])->name('ai_requests.index');
Route::post('/modules/{module}/generate-landing-page', [ModuleController::class, 'generateLandingPage'])->name('modules.generateLandingPage')->middleware('auth');
// Module content page — public, no auth required (mirrors modules.show)
Route::get('/modules/{module}/page', [ModuleController::class, 'page'])->name('modules.page');

Route::post('/modules/{module}/assign-next-step', [ModuleController::class, 'assignNextStep'])
    ->name('modules.assign-next-step')
    ->middleware(['auth', 'can:admin']);

Route::get('/proficiencies/by-subject/{subject}', [ProficiencyController::class, 'bySubject']);
Route::get('/subjects/by-category/{category}', [CategoryController::class, 'subjectsByCategory']);

// Category routes
// routes/web.php

// use this command to create a controller:
// php artisan make:controller QuestionController --model=Question

// Collection routes
Route::get('/collection', [CollectionController::class, 'index'])->name('collection.index')->middleware('auth');

// ------- Backend UI Dashboard ------- CURRENTLY DISABLED //
Route::get('/admindash', [AdminController::class, 'index'])->name('admindash'); // Displays all tables and relationships.

// ------- Admin Content Management -------
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/content', ContentManager::class)->name('content');
    Route::get('/api-usage', ApiUsage::class)->name('api-usage');
    Route::get('/weak-areas', WeakAreas::class)->name('weak-areas');
    Route::get('/diagnostic-stats', DiagnosticStats::class)->name('diagnostic-stats');
    Route::get('/page-usage', PageUsage::class)->name('page-usage');
    Route::get('/logs', LogViewer::class)->name('logs');
    Route::get('/game-data', GameDataBrowser::class)->name('game-data');
    Route::get('/talent-builds', TalentBuildEditor::class)->name('talent-builds');
});

// ------- User-authored guides -------
// {guide} resolves through an explicit binding in AppServiceProvider::boot() — guide slugs are
// only unique per author, so a plain slug lookup can find somebody else's guide. See there.
// The builder is open to guests: Builder::mount() lets in whoever UserGuide::isEditableBy() allows,
// and a guest plan is editable only from the browser holding its cookie (see GuestPlanService).
Route::get('/guides/{guide}/edit', \App\Livewire\Guides\Builder::class)->name('guides.edit');

// Try the planner without an account. POST for the same reason as guides.create below. A signed-in
// player just gets a normal draft. Throttled because anyone, bots included, can reach it.
Route::post('/try/{type}', function (string $type) {
    $guideType = \App\Enums\UserGuideType::tryFrom($type);
    abort_if($guideType === null, 404);

    $guide = auth()->check()
        ? \App\Models\UserGuide::startDraft(auth()->user(), $guideType)
        : app(\App\Http\Services\GuestPlanService::class)->start($guideType);

    return redirect()->route('guides.edit', ['guide' => $guide->slug]);
})->middleware('throttle:10,60')->name('guides.try');

Route::middleware('auth')->prefix('guides')->name('guides.')->group(function () {
    Route::get('/', \App\Livewire\Guides\Index::class)->name('index');

    // Start a guide from anywhere — the mobile nav's Build button posts here. POST, never a link:
    // a GET that creates rows would create one every time a browser prefetched it.
    Route::post('/new/{type}', function (string $type) {
        $guideType = \App\Enums\UserGuideType::tryFrom($type);
        abort_if($guideType === null, 404);

        $guide = \App\Models\UserGuide::startDraft(auth()->user(), $guideType);

        return redirect()->route('guides.edit', ['guide' => $guide->slug]);
    })->middleware('throttle:20,1')->name('create');
});

// ------- Battle.net link + your characters -------
// Owner-only. A character's name only ever reaches another person's screen through a guide its
// owner explicitly attributed to it — see UserGuide::authorCharacter().
//
// The redirect and callback serve guests too: signed in they LINK, signed out they sign in or sign
// up (see BattlenetController). The finish step is the email form a brand-new Battle.net sign-up
// needs, since Blizzard shares no email address.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/auth/battlenet', [\App\Http\Controllers\BattlenetController::class, 'redirect'])->name('battlenet.redirect');
    Route::get('/auth/battlenet/callback', [\App\Http\Controllers\BattlenetController::class, 'callback'])->name('battlenet.callback');
});
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/auth/battlenet/finish', [\App\Http\Controllers\BattlenetController::class, 'finishForm'])->name('battlenet.finish');
    Route::post('/auth/battlenet/finish', [\App\Http\Controllers\BattlenetController::class, 'finishStore'])->name('battlenet.finish.store');
});

// "Continue with Google" — see GoogleAuthController. Guests only: a signed-in player has nothing
// to sign in to.
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('/auth/google', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware('auth')->group(function () {
    Route::delete('/auth/battlenet', [\App\Http\Controllers\BattlenetController::class, 'destroy'])->name('battlenet.unlink');
    Route::get('/characters', \App\Livewire\Battlenet\Characters::class)->name('characters.index');
    Route::get('/characters/{character}', \App\Livewire\Battlenet\CharacterShow::class)->whereNumber('character')->name('characters.show');
});

// Browse: the only place public guides are listed. Deliberately NOT auth-gated — a public guide
// is public, and requiring an account to look at one would defeat the point of publishing.
Route::get('/browse-guides', \App\Livewire\Guides\Browse::class)->name('guides.browse');

// Machine-drafted comp guides, on their own page rather than mixed into the listing above — see
// Guides\MachineGuides for why. Public: the whole point is that anyone can read one and say where
// it is wrong.
Route::get('/claudes-comp-guides', \App\Livewire\Guides\MachineGuides::class)->name('guides.machine');

// The MindCollector Brain: the arena model every machine-drafted guide is written from, published
// so a reader who disagrees with a guide can argue with the thing that produced it. Public for the
// same reason the guides above are — a correction is the entire point, and requiring an account to
// read the model would cut off the people most likely to know it is wrong.
Route::get('/brain', \App\Livewire\Brain::class)->name('brain');

// Strategic ideas with the arena sequence that is an instance of each. Sits at the root next to
// /brain rather than under /wow deliberately: the ideas are meant to be game-neutral even though
// today every worked example is WoW. See App\Livewire\Strategy.
Route::get('/strategy', \App\Livewire\Strategy::class)->name('strategy');

// ------- Guilds -------
// Index is auth-only (it is "your guilds"); the guild page itself is not, because its URL is the
// invite and someone following it may not have an account yet. Guilds\Show decides what a
// non-member may see.
Route::middleware('auth')->get('/guilds', \App\Livewire\Guilds\Index::class)->name('guilds.index');
Route::get('/guilds/{guild}', \App\Livewire\Guilds\Show::class)->name('guilds.show');

// The shared read view lives under /g/{username}/ — its OWN namespace, deliberately not alongside
// /burst-guides, /claudes-guides or /pvp-guides. Those are derived from real match evidence; this
// is one player's own plan, and naming the author in the URL is the cheapest possible way to keep
// the two trust tiers from being mistaken for each other. Not auth-gated: a public guide is
// readable by anyone, and Guides\Show decides access per guide.
Route::get('/g/{username}/{guide}', \App\Livewire\Guides\Show::class)->name('guides.show');

// "Buy me a coffee": a redirect rather than a bare external link so each click is counted
// (PageViewEvent 'support_click', shown on /admin/page-usage). 404s when no URL is configured,
// which the sidebar never links to anyway — see config/services.php.
Route::get('/support', function () {
    $url = config('services.buymeacoffee.url');
    abort_unless(filled($url) && str_starts_with($url, 'https://'), 404);

    \App\Models\PageViewEvent::log('support_click');

    return redirect()->away($url);
})->middleware('throttle:30,1')->name('support');

// Feedback
Route::get('/feedback', [FeedbackController::class, 'create'])->name('feedback.create');
Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

require __DIR__.'/auth.php';
