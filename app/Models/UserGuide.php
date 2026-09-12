<?php

namespace App\Models;

use App\Enums\UserGuideMemberSide;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
        'battlenet_character_id',
        'type',
        'opponent_spec_id',
        'status',
        'visibility',
        'patch_id',
        'title',
        'slug',
        'summary',
        'published_at',
    ];

    protected $casts = [
        'type' => UserGuideType::class,
        'status' => UserGuideStatus::class,
        'visibility' => UserGuideVisibility::class,
        'published_at' => 'datetime',
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
                $guide->slug = $guide->uniqueSlugFrom(Str::slug($guide->title ?? 'guide') ?: 'guide');
            }
        });
    }

    /**
     * Make a slug base unique within this author, matching the composite unique key.
     *
     * A stranger having written "RMD opener" must not push this author onto "rmd-opener-1".
     */
    public function uniqueSlugFrom(string $base): string
    {
        $base = trim($base, '-') ?: 'guide';
        $slug = $base;
        $counter = 1;

        while (self::where('user_id', $this->user_id)
            ->where('slug', $slug)
            ->whereKeyNot($this->getKey() ?? 0)
            ->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    /**
     * A slug that says what the guide is, for the address bar and for search.
     *
     * The problem this solves: the slug is generated when the row is created, which is before the
     * author has typed a title or picked a single spec — so real guides were living at
     * /g/chris/untitled-guide, which tells a reader nothing and tells a search engine less.
     *
     * Built from the title AND the comp, because neither alone is enough. Titles repeat heavily
     * ("The Opener" is the obvious name for half of all guides) and would collide into
     * the-opener-1, the-opener-2 — informative to nobody. The comp is what actually distinguishes
     * them and is also what people search for, so "the-opener-disc-boomy-assa" beats both halves
     * on its own. A named enemy team is appended, since "rmd-vs-rmp" IS the query someone types.
     *
     * Capped at 80 characters on a word boundary: long slugs are not an SEO problem in themselves,
     * but a slug nobody can read back over voice or fit in a chat line is a usability one, and
     * three full "specialization + class" pairs on both sides runs past 120.
     */
    public function descriptiveSlug(): string
    {
        $specNames = fn ($rows) => collect($rows)
            ->map(fn ($m) => $m->specialization?->name)
            ->filter()
            ->values();

        $title = Str::slug($this->title ?? '');

        // "Untitled guide" and friends carry no information, so they are dropped rather than
        // baked into the URL — the comp then leads, which is the more useful half anyway.
        if (Str::startsWith($title, 'untitled')) {
            $title = '';
        }

        $parts = array_filter([
            $title,
            Str::slug($specNames($this->members()->with('specialization')->get())->implode(' ')),
        ]);

        $enemies = $specNames($this->enemies()->with('specialization')->get());
        if ($enemies->isNotEmpty()) {
            $parts[] = 'vs-'.Str::slug($enemies->implode(' '));
        } elseif ($this->opponentSpec) {
            $parts[] = 'vs-'.Str::slug($this->opponentSpec->name);
        }

        $base = implode('-', $parts);

        if (strlen($base) > 80) {
            $base = Str::beforeLast(substr($base, 0, 81), '-');
        }

        return $this->uniqueSlugFrom($base ?: (Str::slug($this->title ?? '') ?: 'guide'));
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
     * The author's own character this guide is "written as", when they chose one. Opt-in, and the
     * only way a character's name reaches a page other people can read.
     */
    public function authorCharacter()
    {
        return $this->belongsTo(BattlenetCharacter::class, 'battlenet_character_id');
    }

    /**
     * Who the guide is by, as readers see it: the signing character as "Name-Realm" when the
     * author signed it with one, otherwise their MindCollector username. One definition, so the
     * listing card, the guide page and "My Guides" can never name the same author differently.
     *
     * Guarded on the column so an unsigned guide never costs a query on pages that did not
     * eager-load the relation.
     */
    public function authorLabel(): string
    {
        $character = $this->battlenet_character_id ? $this->authorCharacter : null;

        return $character?->fullName() ?? $this->user?->username ?? $this->user?->name ?? 'unknown';
    }

    /** The signing character's class colour, or null for an unsigned guide. */
    public function authorColor(): ?string
    {
        $character = $this->battlenet_character_id ? $this->authorCharacter : null;

        return $character ? (config('wow_classes.colors')[$character->gameClass?->slug] ?? null) : null;
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
     * Likes first, views as the tie-break. Views alone would rank whatever has been linked the
     * most rather than what people found useful, and likes alone leave every new guide tied on
     * zero with nothing to separate them — a guide nobody has liked yet but forty people have
     * read is still the better of two unliked guides.
     */
    public function scopeForComp(Builder $query, array $specIds): Builder
    {
        $key = self::compKeyFor($specIds);

        return $query->listed()
            ->when($key === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->where('comp_key', $key)
            ->orderByDesc('like_count')
            ->orderByDesc('view_count');
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

    public function likes()
    {
        return $this->hasMany(UserGuideLike::class);
    }

    public function likedBy(?User $user): bool
    {
        return $user !== null && $this->likes()->where('user_id', $user->id)->exists();
    }

    public function comments()
    {
        return $this->hasMany(UserGuideComment::class)->orderBy('created_at');
    }

    /**
     * Recompute the denormalised like count from the likes table.
     *
     * Called on every like write. user_guide_likes stays the source of truth; the column exists
     * so "the best guides for this comp" is an ORDER BY on an indexed value rather than an
     * aggregate over a join, on a page (/wow-comps) that is already the heaviest on the site.
     */
    public function syncLikeCount(): void
    {
        $this->forceFill(['like_count' => $this->likes()->count()])->save();
    }

    /**
     * Count one view, without a write on every single page load.
     *
     * Deduplicated per viewer per guide per day, in the cache rather than a table: the honest
     * question a view count answers is "how many people looked at this", and counting a refresh
     * or a back-button as a new reader answers a different, more flattering one. A table of view
     * events would answer it more precisely, but it grows without bound for a number that is only
     * ever rendered as a rough total.
     *
     * Guests are counted too (keyed on session id) — most readers of a public guide will not be
     * logged in, and a count that ignored them would be wrong in the direction that makes the
     * feature useless. The author's own views are NOT counted: an author reloading their own
     * guide while writing it would otherwise be its biggest audience.
     *
     * Uses an atomic increment, so two readers landing at once cannot lose one of the two.
     */
    public function recordView(?User $viewer, string $sessionId): void
    {
        if ($this->isOwnedBy($viewer)) {
            return;
        }

        $who = $viewer?->id ? 'u'.$viewer->id : 's'.$sessionId;
        $key = "guide_view:{$this->id}:{$who}";

        if (! Cache::add($key, 1, now()->addDay())) {
            return;
        }

        static::whereKey($this->id)->increment('view_count');
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
