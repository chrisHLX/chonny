<?php

namespace App\Models;

use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A user-authored guide: a comp, and one or more named sections (chains, gos, an opponent's
 * defensives, prose).
 *
 * Read the migrations for the schema reasoning — create_user_guides_table (why this is a parallel
 * artifact type rather than an edit of the derived corpus, why status is a string column),
 * create_user_guide_members_table (why the comp is a table, not columns), and
 * create_user_guide_sections_table (why kind moved off this model).
 *
 * Route-bound on `slug`, matching Module. Slugs are unique PER AUTHOR, not globally, because the
 * public URL is /g/{username}/{slug} — see the add_guide_sharing migration.
 */
class UserGuide extends Model
{
    protected $fillable = [
        'user_id',
        'guild_id',
        'type',
        'opponent_spec_id',
        'status',
        'visibility',
        'patch_id',
        'title',
        'slug',
        'summary',
    ];

    protected $casts = [
        'type' => UserGuideType::class,
        'status' => UserGuideStatus::class,
        'visibility' => UserGuideVisibility::class,
    ];

    /**
     * A guide is a comp guide unless it says otherwise — set HERE and not only as the column
     * default, because a column default is applied by the database and is NOT reflected on the
     * in-memory model a create() returns. Without this, a freshly-created guide has `type = null`
     * for the rest of the request, and UserGuideChainService::groupsFor() calls
     * `$section->guide->type->usesWholeKit()` straight on it. That is a fatal error on the very
     * first render of a new guide, and it would only appear once the row was reloaded from the
     * database — which is exactly when a test would stop reproducing it.
     */
    protected $attributes = [
        'type' => UserGuideType::Comp->value,
    ];

    /**
     * Slug generation mirrors ModulePage::booted() — generate from the title only when one was not
     * supplied, and suffix on collision rather than failing the insert against the unique index.
     * Deliberately does NOT regenerate on rename: the slug is the public URL, and silently moving
     * a shared guide because its author fixed a typo in the title would break every existing link.
     *
     * Collision is checked WITHIN THE AUTHOR only, matching the composite unique key. A stranger
     * having written "RMD opener" must not push this author onto "rmd-opener-1".
     */
    protected static function booted(): void
    {
        static::creating(function (self $guide) {
            if (empty($guide->slug)) {
                $base = Str::slug($guide->title ?? 'guide') ?: 'guide';
                $slug = $base;
                $counter = 1;

                while (self::where('user_id', $guide->user_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$counter++;
                }

                $guide->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The comp this guide is written for, in slot order. Two or three specs for a comp guide;
     * exactly one — the spec the guide is about — for a class guide.
     *
     * The author's OWN comp. Deliberately still called members() and still scoped to one side, so
     * every existing caller — the bracket, the palette, the roster guards — keeps meaning exactly
     * what it meant before the enemy team existed, rather than silently starting to include it.
     */
    public function members()
    {
        return $this->hasMany(UserGuideMember::class)
            ->where('side', UserGuideMemberSide::Team->value)
            ->orderBy('position');
    }

    /**
     * The comp being played against — what makes a guide a MATCHUP rather than just a rotation.
     *
     * Same table, same slots, same talent builds as your own comp, so "their Priest is running
     * Ultimate Penitence" is expressible. Empty for a guide with no named opposition, which stays
     * a perfectly good guide.
     */
    public function enemies()
    {
        return $this->hasMany(UserGuideMember::class)
            ->where('side', UserGuideMemberSide::Enemy->value)
            ->orderBy('position');
    }

    /** Every roster row, both sides — for eager loading and cascade-shaped work only. */
    public function roster()
    {
        return $this->hasMany(UserGuideMember::class)->orderBy('side')->orderBy('position');
    }

    /** Whether an enemy team has been named at all. */
    public function hasEnemies(): bool
    {
        return $this->enemies()->exists();
    }

    /**
     * The single opponent a class guide is written against ("Rogue vs Disc"), or null for a
     * rotation or technique guide with no opponent. Never set on a comp guide, whose opponents are
     * per-section instead.
     */
    public function opponentSpec()
    {
        return $this->belongsTo(Specialization::class, 'opponent_spec_id');
    }

    public function isClassGuide(): bool
    {
        return $this->type === UserGuideType::ClassGuide;
    }

    /** How many comp slots this guide has — see UserGuideType::maxMembers(). */
    public function maxMembers(): int
    {
        return $this->type->maxMembers();
    }

    /** How many enemy slots this guide has — see UserGuideType::maxEnemies(). */
    public function maxEnemies(): int
    {
        return $this->type->maxEnemies();
    }

    /** Slot count for one side, so callers do not branch on the side themselves. */
    public function maxSlotsFor(UserGuideMemberSide $side): int
    {
        return $side === UserGuideMemberSide::Enemy ? $this->maxEnemies() : $this->maxMembers();
    }

    /** Sections in page order: down by row, then left to right. */
    public function sections()
    {
        return $this->hasMany(UserGuideSection::class)->orderBy('row')->orderBy('column');
    }

    /** People the author has explicitly given read access to a private guide. */
    public function viewers()
    {
        return $this->belongsToMany(User::class, 'user_guide_viewers')->withTimestamps();
    }

    /**
     * The patch this guide was authored against. Informational only — blocks resolve their
     * abilities through Blizzard's external spell id, never through this patch, so a guide keeps
     * working after a patch bump and after this row is gone.
     */
    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    /** Every block in the guide, in page order. Blocks hang off sections, not off the guide. */
    public function blocks()
    {
        return $this->hasManyThrough(
            UserGuideBlock::class,
            UserGuideSection::class,
            'user_guide_id',
            'user_guide_section_id',
        );
    }

    /** Guides that may appear in a public listing. Private and guild ones never do. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', UserGuideStatus::Published->value)
            ->where('visibility', UserGuideVisibility::Public->value);
    }

    /**
     * Public guides written for exactly this comp, best first.
     *
     * Deliberately an equality test on the denormalised key rather than a join over the roster:
     * this runs on /wow-comps, and the whole point of the feature is to surface a handful of good
     * guides rather than every guide anyone has ever written.
     *
     * Unrated guides sort last rather than first — a NULL average is "nobody has said", which is
     * weaker evidence than a low score, but it must not outrank a guide people actually liked.
     */
    public function scopeForComp(Builder $query, array $specIds): Builder
    {
        $key = self::compKeyFor($specIds);

        return $query->listed()
            ->when($key === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->where('comp_key', $key)
            ->orderByRaw('rating_avg IS NULL, rating_avg DESC')
            ->orderByDesc('rating_count');
    }

    /**
     * The bracket this guide describes, derived from roster size rather than stored — a stored
     * bracket would be a second thing to keep in sync and could immediately disagree with the
     * roster it is meant to describe. Null until at least two members exist, because one spec on
     * its own is not a comp.
     */
    public function bracket(): ?string
    {
        // A class guide is never a bracket, however many rows its roster happens to have — it is
        // about one spec, and labelling it "2v2" because an opponent exists would be wrong.
        if ($this->isClassGuide()) {
            return null;
        }

        return match ($this->members()->count()) {
            2 => '2v2',
            3 => '3v3',
            default => null,
        };
    }

    /**
     * Whether this guide has enough of a comp to be worth publishing. Every guide needs at least
     * one spec — without one there is no kit to draw abilities from, so the guide is necessarily
     * empty. The schema cannot express "at least one row in a related table".
     */
    public function hasRoster(): bool
    {
        return $this->members()->exists();
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $user->id === $this->user_id;
    }

    /**
     * Who may read this guide.
     *
     * Three gates, in order: the author always can; nobody else can read a draft; and a published
     * guide is world-readable only when its visibility says so, otherwise it needs an explicit
     * invite. Written as one method so every caller — the public page, the builder, a future
     * listing — asks the same question rather than each assembling its own combination of status
     * and visibility.
     */
    /** The guild this guide is shared with, when its visibility says so. */
    public function guild()
    {
        return $this->belongsTo(Guild::class);
    }

    public function ratings()
    {
        return $this->hasMany(UserGuideRating::class);
    }

    public function comments()
    {
        return $this->hasMany(UserGuideComment::class)->orderBy('created_at');
    }

    /**
     * Recompute the denormalised rating from the ratings table.
     *
     * Called on every rating write. user_guide_ratings stays the source of truth; these two
     * columns exist so "the best guides for this comp" is an ORDER BY on an indexed value rather
     * than an aggregate over a join, on a page (/wow-comps) that is already the heaviest on the
     * site.
     */
    public function recalculateRating(): void
    {
        $this->forceFill([
            'rating_count' => $this->ratings()->count(),
            'rating_avg' => $this->ratings()->avg('value'),
        ])->save();
    }

    /**
     * The guide's comp as a sorted, hyphen-joined list of spec ids ("3-17-24"), or null when it
     * has no roster yet.
     *
     * SORTED, so the same three specs always produce the same key however the author ordered
     * their slots — a comp is a set, not an ordering, and "Rogue/Mage/Priest" must match
     * "Priest/Rogue/Mage". Built from the author's OWN side only: a guide is filed under the comp
     * it teaches, not the one it is played against.
     */
    public function compKeyFromRoster(): ?string
    {
        $ids = $this->members()->pluck('spec_id')->filter()->unique()->sort()->values();

        return $ids->isEmpty() ? null : $ids->implode('-');
    }

    /** Rebuild comp_key after a roster change. Cheap, and always safe to call. */
    public function syncCompKey(): void
    {
        $key = $this->compKeyFromRoster();

        if ($this->comp_key !== $key) {
            $this->forceFill(['comp_key' => $key])->save();
        }
    }

    /** The same key for an arbitrary set of spec ids, so a lookup can be built the same way. */
    public static function compKeyFor(array $specIds): ?string
    {
        $ids = collect($specIds)->filter()->unique()->sort()->values();

        return $ids->isEmpty() ? null : $ids->implode('-');
    }

    public function isReadableBy(?User $user): bool
    {
        if ($this->isOwnedBy($user)) {
            return true;
        }

        if ($this->status !== UserGuideStatus::Published) {
            return false;
        }

        if ($this->visibility === UserGuideVisibility::Public) {
            return true;
        }

        // A guild-visible guide whose guild has been deleted falls through to the explicit-viewer
        // check below rather than becoming unreadable or public — the author's intent survives if
        // the guild is ever recreated, and nothing leaks in the meantime.
        if ($this->visibility === UserGuideVisibility::Guild
            && $this->guild
            && $this->guild->hasMember($user)) {
            return true;
        }

        return $user !== null && $this->viewers()->whereKey($user->id)->exists();
    }

    /** The canonical shareable URL, or null while the author has no username to build one from. */
    public function publicUrl(): ?string
    {
        $username = $this->user?->username;

        return $username ? route('guides.show', ['username' => $username, 'guide' => $this->slug]) : null;
    }
}
