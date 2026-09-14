<?php

namespace App\Http\Services;

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Models\TalentBuild;
use App\Models\User;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideMember;
use App\Models\UserGuideSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies a guide so it can be reused as the starting point for another one.
 *
 * Asked for directly (2026-09-14): writing "Jungle vs RMP", "Jungle vs Turbo" and "Jungle vs
 * TSG" meant rebuilding the same comp, talents, opener and go three times, when what actually
 * differs between them is the enemy side and a few notes. Copy the guide, then change what the
 * matchup changes.
 *
 * WHAT IS COPIED: everything that is the plan — type, summary, both sides of the roster, each
 * slot's talent build, every section in place, every step and its note, and the class-guide
 * opponent. WHAT IS NOT: anything that is about the ORIGINAL guide being out in the world. The
 * copy is a private draft (never inherits "published" or "public"), has no likes, views, readers
 * or guild, and nobody else can edit it until the author turns that on again — a copy must never
 * go live, or open itself to other people, as a side effect of being made.
 *
 * TALENT BUILDS ARE CLONED, NOT SHARED. A slot's build is its own row (see
 * TalentSelectionService::getOrCreateGuideMemberBuild()), and pointing two guides at one row
 * would mean editing talents in the copy silently rewrote the original — the two-sources-of-truth
 * trap. A clone is still user_id NULL + is_default FALSE, so it stays invisible outside the guide.
 *
 * Copied steps and sections are credited to whoever made the copy. The collaborators on the
 * original keep their credit THERE; listing them as contributors on a guide they never opened
 * would be wrong in the other direction.
 */
class UserGuideDuplicator
{
    public function duplicate(UserGuide $original, User $author): UserGuide
    {
        return DB::transaction(function () use ($original, $author) {
            $copy = UserGuide::create([
                'user_id' => $author->id,
                'type' => $original->type,
                'opponent_spec_id' => $original->opponent_spec_id,
                // Signing is a per-guide choice the same author made, so it carries over; for
                // anyone else it would put their name on a character that isn't theirs.
                'battlenet_character_id' => $original->isOwnedBy($author) ? $original->battlenet_character_id : null,
                'status' => UserGuideStatus::Draft,
                'visibility' => UserGuideVisibility::Invited,
                'friends_can_edit' => false,
                'guild_can_edit' => false,
                'last_edited_by_user_id' => $author->id,
                'patch_id' => $original->patch_id,
                'title' => $this->copyTitle($original->title),
                'summary' => $original->summary,
            ]);

            foreach ($original->roster()->with(['talentBuild.choices', 'talentBuild.pvpChoices'])->get() as $member) {
                UserGuideMember::create([
                    'user_guide_id' => $copy->id,
                    'side' => $member->side,
                    'position' => $member->position,
                    'spec_id' => $member->spec_id,
                    'talent_build_id' => $member->talentBuild ? $this->cloneBuild($member->talentBuild)->id : null,
                ]);
            }

            foreach ($original->sections()->with('blocks')->get() as $section) {
                $newSection = UserGuideSection::create([
                    'user_guide_id' => $copy->id,
                    'kind' => $section->kind,
                    'title' => $section->title,
                    'row' => $section->row,
                    'column' => $section->column,
                    'opponent_spec_id' => $section->opponent_spec_id,
                    'body' => $section->body,
                    'created_by_user_id' => $author->id,
                    'updated_by_user_id' => $author->id,
                ]);

                foreach ($section->blocks as $block) {
                    UserGuideBlock::create([
                        'user_guide_section_id' => $newSection->id,
                        'position' => $block->position,
                        'block_type' => $block->block_type,
                        'payload' => $block->payload,
                        'added_by_user_id' => $author->id,
                    ]);
                }
            }

            $copy->syncCompKey();

            return $copy;
        });
    }

    /** "Jungle vs RMP" → "Jungle vs RMP (copy)", kept inside the 120-character title limit. */
    private function copyTitle(?string $title): string
    {
        $title = trim((string) $title) ?: 'Untitled guide';

        return Str::limit($title, 113, '').' (copy)';
    }

    private function cloneBuild(TalentBuild $build): TalentBuild
    {
        $clone = TalentBuild::create([
            'user_id' => null,
            'is_default' => false,
            'spec_id' => $build->spec_id,
            'patch_id' => $build->patch_id,
            'spellbook_snapshot_id' => $build->spellbook_snapshot_id,
            'name' => $build->name,
            'share_slug' => (string) Str::uuid(),
        ]);

        $now = now();

        // Bulk inserts: a build is ~100 picks, and a full 3v3 matchup can carry six builds.
        $choices = $build->choices->map(fn ($c) => [
            'talent_build_id' => $clone->id,
            'talent_node_id' => $c->talent_node_id,
            'chosen_entry_id' => $c->chosen_entry_id,
            'rank' => $c->rank,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($choices !== []) {
            DB::table('talent_build_choices')->insert($choices);
        }

        $pvp = $build->pvpChoices->map(fn ($c) => [
            'talent_build_id' => $clone->id,
            'slot' => $c->slot,
            'pvp_talent_id' => $c->pvp_talent_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($pvp !== []) {
            DB::table('talent_build_pvp_choices')->insert($pvp);
        }

        return $clone;
    }
}
