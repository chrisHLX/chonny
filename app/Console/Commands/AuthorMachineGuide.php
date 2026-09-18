<?php

namespace App\Console\Commands;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\TalentFeasibilityService;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Publish a machine-drafted guide from a committed JSON draft.
 *
 * WHY THIS EXISTS RATHER THAN A TINKER SESSION. CLAUDE.md already documents that multi-line PHP
 * through `php artisan tinker --execute` gets mangled across the quoting layers, but the real
 * reasons are bigger: a guide written in a REPL exists only in that database, cannot be reviewed
 * in a diff, cannot be re-run after a patch changes an ability, and cannot be reproduced on a
 * second environment. A draft file can be read, corrected in the editor, committed, and applied
 * identically here and on production.
 *
 * IDEMPOTENT, keyed by slug. Re-running replaces the guide's sections and steps wholesale — the
 * draft file is the source of truth — while keeping the guide row itself, so its URL, its views
 * and every note readers have already attached to it survive an edit. Notes anchored to a step
 * that the new draft no longer contains are deleted with that step (the anchor cascades), which
 * is correct: a note about a step that no longer exists has nothing to say.
 *
 * Abilities are referenced by NAME in the draft and resolved to an external spell id here, once,
 * against the current patch. Nothing about a spell is frozen into the guide: the cooldowns,
 * durations, DR maths and immunities a reader sees are resolved live on every page load, the
 * same as for a player's guide.
 */
class AuthorMachineGuide extends Command
{
    protected $signature = 'guides:author
        {path : A draft JSON file, or a directory of them}
        {--author=mindcollector : The account that owns these guides}
        {--model=Claude Opus 5 : The byline, stored on the guide}
        {--draft : Write it unpublished, to be read by its author only}
        {--dry-run : Resolve and check the draft without writing anything}';

    protected $description = 'Create or update machine-drafted guides from committed JSON drafts';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));

        if (! File::exists($path)) {
            $path = $this->argument('path');
        }

        if (! File::exists($path)) {
            $this->error("No such file or directory: {$this->argument('path')}");

            return self::FAILURE;
        }

        $files = File::isDirectory($path)
            ? collect(File::files($path))->filter(fn ($f) => $f->getExtension() === 'json')->map->getPathname()->values()->all()
            : [$path];

        if ($files === []) {
            $this->warn('No draft files found.');

            return self::SUCCESS;
        }

        $author = User::where('username', $this->option('author'))->first();

        if (! $author) {
            $this->error("No account with username '{$this->option('author')}'.");

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($files as $file) {
            $this->line('');
            $this->info(basename($file));

            $draft = json_decode(File::get($file), true);

            if (! is_array($draft)) {
                $this->error('  Not valid JSON — skipped.');
                $failed++;

                continue;
            }

            try {
                if ($this->option('dry-run')) {
                    $this->preflight($draft);
                } else {
                    $this->apply($draft, $author);
                }
            } catch (\Throwable $e) {
                $this->error('  '.$e->getMessage());
                $failed++;
            }
        }

        $this->line('');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function apply(array $draft, User $author): void
    {
        foreach (['slug', 'title', 'team', 'sections'] as $required) {
            if (! isset($draft[$required])) {
                throw new \RuntimeException("Draft is missing '{$required}'.");
            }
        }

        $guide = DB::transaction(function () use ($draft, $author) {
            $guide = UserGuide::where('user_id', $author->id)->where('slug', $draft['slug'])->first();

            $attributes = [
                'user_id' => $author->id,
                'authored_by_model' => $this->option('model'),
                'type' => UserGuideType::Comp,
                'title' => $draft['title'],
                'summary' => $draft['summary'] ?? null,
                'status' => $this->option('draft') ? UserGuideStatus::Draft : UserGuideStatus::Published,
                'visibility' => UserGuideVisibility::Public,
                'slug' => $draft['slug'],
            ];

            if ($guide) {
                $guide->update($attributes);
            } else {
                $guide = UserGuide::create($attributes + ['published_at' => now()]);
            }

            if ($guide->published_at === null && ! $this->option('draft')) {
                $guide->update(['published_at' => now()]);
            }

            $this->syncRoster($guide, $draft['team'] ?? [], UserGuideMemberSide::Team);
            $this->syncRoster($guide, $draft['enemy'] ?? [], UserGuideMemberSide::Enemy);
            $this->syncSections($guide, $draft['sections']);

            $guide->syncCompKey();

            return $guide;
        });

        $this->line('  '.$guide->title);
        $this->line('  /g/'.$author->username.'/'.$guide->slug);

        $this->reportTalentConflicts($draft);
    }

    /**
     * Resolve every reference in a draft and report on it, without writing a row.
     *
     * This is the loop that authoring a draft actually runs in: a name that resolves to nothing,
     * or a pair of abilities no single build can hold, should be found while the draft is still a
     * file being edited — not after it is published and a reader has to point it out.
     */
    private function preflight(array $draft): void
    {
        foreach (['slug', 'title', 'team', 'sections'] as $required) {
            if (! isset($draft[$required])) {
                throw new \RuntimeException("Draft is missing '{$required}'.");
            }
        }

        foreach (array_merge($draft['team'] ?? [], $draft['enemy'] ?? []) as $ref) {
            $this->spec($ref);
        }

        $steps = 0;

        foreach ($draft['sections'] as $section) {
            if (UserGuideSectionKind::tryFrom($section['kind'] ?? 'sequence') === null) {
                throw new \RuntimeException("Unknown section kind '{$section['kind']}'.");
            }

            if (isset($section['opponent'])) {
                $this->spec($section['opponent']);
            }

            foreach ($section['steps'] ?? [] as $step) {
                $spec = $this->spec($step['spec']);
                $spell = $this->spell($step['spell'], $spec);
                $steps++;

                if ($spell->name !== $step['spell']) {
                    $this->line("  <fg=gray>'{$step['spell']}' → {$spell->display_name} (#{$spell->spell_id})</>");
                }
            }
        }

        $this->line('  '.count($draft['sections']).' section(s), '.$steps.' step(s) — all references resolve.');

        $this->reportTalentConflicts($draft);
    }

    /**
     * Warn when one character in the plan is asked to press two abilities no single build holds.
     *
     * A WARNING, NOT A FAILURE. The check is deliberately narrow — choice-node exclusivity only
     * (see TalentFeasibilityService) — and a guide is allowed to discuss an ability the enemy
     * might have taken, or to name an alternative in a defensives section. What it must not do is
     * build a sequence out of two picks that exclude each other and say nothing about it. Printing
     * loudly and letting the author judge is the right side of that line; failing the import would
     * block legitimate drafts.
     */
    private function reportTalentConflicts(array $draft): void
    {
        $bySpec = [];

        foreach ($draft['sections'] as $section) {
            foreach ($section['steps'] ?? [] as $step) {
                $bySpec[$step['spec']][] = $step['spell'];
            }
        }

        $feasibility = app(TalentFeasibilityService::class);

        foreach ($bySpec as $ref => $names) {
            $result = $feasibility->check($this->spec($ref), $names);

            foreach ($result['conflicts'] as $conflict) {
                $this->warn('  TALENT CONFLICT ('.$ref.'): '.implode(' / ', $conflict['abilities'])
                    .' share choice node '.$conflict['node'].' in '.$conflict['tree']
                    .' — one build cannot hold both.');
            }
        }
    }

    /** @param array<int, string> $specs "rogue/subtlety" strings, in slot order */
    private function syncRoster(UserGuide $guide, array $specs, UserGuideMemberSide $side): void
    {
        // roster(), not members() — that relation is already scoped to the team side, so
        // members()->where('side', 'enemy') matches nothing and the re-import then collides with
        // the rows it was supposed to have replaced. Caught re-importing a revised guide.
        $guide->roster()->where('side', $side->value)->delete();

        foreach (array_values($specs) as $position => $ref) {
            $spec = $this->spec($ref);

            UserGuideMember::create([
                'user_guide_id' => $guide->id,
                'side' => $side->value,
                'position' => $position,
                'spec_id' => $spec->id,
            ]);
        }
    }

    private function syncSections(UserGuide $guide, array $sections): void
    {
        // Replaced wholesale: the draft file is the source of truth for a machine guide, and a
        // merge would leave steps behind that the draft has deliberately dropped.
        $guide->sections()->delete();

        foreach (array_values($sections) as $row => $section) {
            $kind = UserGuideSectionKind::tryFrom($section['kind'] ?? 'sequence');

            if ($kind === null) {
                throw new \RuntimeException("Unknown section kind '{$section['kind']}'.");
            }

            $created = UserGuideSection::create([
                'user_guide_id' => $guide->id,
                'kind' => $kind,
                'title' => $section['title'] ?? $kind->label(),
                'body' => $section['body'] ?? null,
                'row' => $section['row'] ?? $row,
                'column' => $section['column'] ?? 0,
                'opponent_spec_id' => isset($section['opponent']) ? $this->spec($section['opponent'])->id : null,
            ]);

            foreach (array_values($section['steps'] ?? []) as $i => $step) {
                $spec = $this->spec($step['spec']);
                $spell = $this->spell($step['spell'], $spec);

                UserGuideBlock::create([
                    'user_guide_section_id' => $created->id,
                    'position' => $i + 1,
                    'block_type' => UserGuideBlockType::Spell,
                    'payload' => array_filter([
                        'external_spell_id' => $spell->spell_id,
                        'source_spec_id' => $spec->id,
                        'note' => $step['note'] ?? null,
                    ], fn ($v) => $v !== null),
                ]);
            }
        }
    }

    private function spec(string $ref): Specialization
    {
        [$class, $spec] = array_pad(explode('/', $ref, 2), 2, null);

        $found = Specialization::whereHas('gameClass', fn ($q) => $q->where('slug', $class))
            ->where('slug', $spec)
            ->first();

        if (! $found) {
            throw new \RuntimeException("Unknown spec '{$ref}' (expected class-slug/spec-slug).");
        }

        return $found;
    }

    /**
     * Resolve an ability by name against the current patch.
     *
     * Prefers a copy that is visible and carries real cooldown data — the same "one visible
     * ability, several internal spell_id copies" problem the rest of this codebase deals with
     * everywhere. A name that resolves to nothing fails loudly rather than writing a step that
     * would render as "ability no longer found".
     */
    private function spell(string $name, Specialization $spec): Spell
    {
        $candidates = Spell::whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name', 'like', $name.' (desc=%'))
            ->get();

        if ($candidates->isEmpty()) {
            throw new \RuntimeException("No spell named '{$name}' in the current patch.");
        }

        $best = $candidates
            ->sortBy([
                fn ($a, $b) => ($a->not_in_spellbook ? 1 : 0) <=> ($b->not_in_spellbook ? 1 : 0),
                fn ($a, $b) => ($a->is_passive ? 1 : 0) <=> ($b->is_passive ? 1 : 0),
                fn ($a, $b) => ($b->dr_category !== null ? 1 : 0) <=> ($a->dr_category !== null ? 1 : 0),
                fn ($a, $b) => ($b->cooldown_seconds !== null ? 1 : 0) <=> ($a->cooldown_seconds !== null ? 1 : 0),
                fn ($a, $b) => $a->spell_id <=> $b->spell_id,
            ])
            ->first();

        return $best;
    }
}
