<?php

namespace App\Models;

use App\Enums\UserGuideSectionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One named section of a guide — a chain, a go, the defensives you are trying to force, or prose.
 * See the create_user_guide_sections_table migration for the (row, column) layout model and why
 * kind lives here rather than on the guide.
 */
class UserGuideSection extends Model
{
    /** Two columns: your side, and the opponent's. Arena is not a spreadsheet. */
    public const MAX_COLUMNS = 2;

    protected $fillable = [
        'user_guide_id',
        'kind',
        'title',
        'row',
        'column',
        'opponent_spec_id',
        'body',
    ];

    protected $casts = [
        'kind' => UserGuideSectionKind::class,
        'row' => 'integer',
        'column' => 'integer',
    ];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    /** Steps in author order. */
    public function blocks()
    {
        return $this->hasMany(UserGuideBlock::class, 'user_guide_section_id')->orderBy('position');
    }

    /** Whose defensives this section is about — only ever set on a Defensives section. */
    public function opponentSpec()
    {
        return $this->belongsTo(Specialization::class, 'opponent_spec_id');
    }

    /**
     * The section's prose as HTML.
     *
     * Uses the exact settings every other user-supplied Markdown in this codebase already renders
     * with (ModulePage, SubjectContent): raw HTML stripped, unsafe links refused. That matters more
     * here than it does there — a ModulePage is written by the site owner, whereas this is written
     * by any signed-in user and shown to other people, so the input is genuinely untrusted.
     */
    public function bodyHtml(): string
    {
        if ($this->kind !== UserGuideSectionKind::Text || blank($this->body)) {
            return '';
        }

        return Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
