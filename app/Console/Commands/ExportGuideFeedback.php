<?php

namespace App\Console\Commands;

use App\Models\UserGuide;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Export what readers said about the machine-drafted guides, as markdown.
 *
 * THE POINT OF THE WHOLE LOOP. A machine guide is published to be corrected; the corrections
 * arrive as notes attached to individual steps, and this is how they come back to be read and
 * folded into arena-structure.md — which is where this project keeps what it knows, and the only
 * thing that carries knowledge from one session to the next.
 *
 * Output is deliberately markdown rather than JSON: a person reads this, and so does a model, and
 * the step a note is attached to has to appear next to the note or the criticism is unreadable
 * out of context.
 *
 * Every guide's own steps are printed too, not just the notes, so the file is a complete record
 * of what was published and what was said about it. Run it on the server, where the notes are.
 */
class ExportGuideFeedback extends Command
{
    protected $signature = 'guides:export-feedback
        {--out= : Where to write the markdown (default: storage/app/guide-feedback.md)}
        {--all : Include player-written guides too, not just machine-drafted ones}';

    protected $description = 'Export machine-guide notes and comments as markdown for review';

    public function handle(): int
    {
        $guides = UserGuide::query()
            ->when(! $this->option('all'), fn ($q) => $q->machineAuthored())
            ->with([
                'user',
                'members.specialization.gameClass',
                'enemies.specialization.gameClass',
                'sections.blocks',
                'comments.user',
            ])
            ->orderBy('id')
            ->get();

        $out = [];
        $out[] = '# Guide feedback';
        $out[] = '';
        $out[] = 'Exported '.now()->toDateString().' from '.config('app.url').'.';
        $out[] = '';

        $noteTotal = 0;

        foreach ($guides as $guide) {
            $team = $this->roster($guide->members);
            $enemy = $this->roster($guide->enemies);

            $out[] = '---';
            $out[] = '';
            $out[] = '## '.$guide->title;
            $out[] = '';
            $out[] = '- `/g/'.$guide->user?->username.'/'.$guide->slug.'`';
            $out[] = '- '.($guide->authored_by_model ?? 'player-written').', '.$guide->status->value
                .', '.$guide->view_count.' views, '.$guide->like_count.' likes';
            $out[] = '- Team: '.($team ?: '—').($enemy ? ' — vs '.$enemy : '');
            $out[] = '';

            $byAnchor = $guide->comments->groupBy(function ($c) {
                return $c->user_guide_block_id
                    ? 'block:'.$c->user_guide_block_id
                    : ($c->user_guide_section_id ? 'section:'.$c->user_guide_section_id : 'guide');
            });

            foreach ($guide->sections->sortBy([['row', 'asc'], ['column', 'asc']]) as $section) {
                $out[] = '### '.$section->title.'  _('.$section->kind->value.')_';
                $out[] = '';

                if ($section->body) {
                    $out[] = '> '.str_replace("\n", "\n> ", trim($section->body));
                    $out[] = '';
                }

                foreach ($section->blocks->sortBy('position') as $block) {
                    $name = $this->stepName($block);
                    $out[] = '- **'.$block->position.'.** '.$name
                        .(isset($block->payload['note']) ? '  — _'.$block->payload['note'].'_' : '');

                    foreach ($byAnchor['block:'.$block->id] ?? [] as $note) {
                        $noteTotal++;
                        $out[] = '    - **NOTE from @'.($note->user?->username ?? '?').'** ('
                            .$note->created_at->toDateString().'): '.$note->body;
                    }
                }

                foreach ($byAnchor['section:'.$section->id] ?? [] as $note) {
                    $noteTotal++;
                    $out[] = '- **NOTE on this section from @'.($note->user?->username ?? '?').'** ('
                        .$note->created_at->toDateString().'): '.$note->body;
                }

                $out[] = '';
            }

            $guideLevel = $byAnchor['guide'] ?? collect();

            if ($guideLevel->isNotEmpty()) {
                $out[] = '### Comments on the guide as a whole';
                $out[] = '';

                foreach ($guideLevel as $c) {
                    $noteTotal++;
                    $out[] = '- **@'.($c->user?->username ?? '?').'** ('.$c->created_at->toDateString().'): '.$c->body;
                }

                $out[] = '';
            }
        }

        $path = $this->option('out') ?: storage_path('app/guide-feedback.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $out)."\n");

        $this->info($guides->count().' guide(s), '.$noteTotal.' note(s) → '.$path);

        return self::SUCCESS;
    }

    private function roster($members): string
    {
        return $members
            ->map(fn ($m) => trim(($m->specialization?->name ?? '?').' '.($m->specialization?->gameClass?->name ?? '')))
            ->filter()
            ->implode(' / ');
    }

    /**
     * The step's ability name, resolved at export time rather than read from the payload — a
     * guide stores a reference, never a resolved name (see the block payload rules).
     */
    private function stepName($block): string
    {
        $id = $block->payload['external_spell_id'] ?? null;

        if ($id === null) {
            return '(no ability)';
        }

        $spell = \App\Models\Spell::whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->where('spell_id', $id)
            ->first();

        return $spell ? $spell->display_name.' ['.($spell->dr_category ?? '—').']' : "(unknown spell {$id})";
    }
}
