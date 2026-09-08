<?php

namespace App\Models;

use App\Enums\GuildRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A named group of players who can see each other's guides.
 *
 * Membership is what grants access — a guild is not a folder guides are copied into, so adding
 * somebody to the guild gives them every guild-visible guide at once and removing them takes all
 * of them away, without touching a single guide row.
 *
 * Route-bound on slug, matching Module and UserGuide. The slug is also the invite: anyone with
 * the URL can join. See the migration for why that is the deliberate choice.
 */
class Guild extends Model
{
    protected $fillable = ['owner_id', 'name', 'slug', 'description'];

    /** Slug generation mirrors UserGuide::booted() — suffix on collision, never regenerate on rename. */
    protected static function booted(): void
    {
        static::creating(function (self $guild) {
            if (empty($guild->slug)) {
                $base = Str::slug($guild->name ?? 'guild') ?: 'guild';
                $slug = $base;
                $n = 1;

                while (self::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }

                $guild->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps()->orderBy('name');
    }

    /** Guides shared with this guild. A guide names one guild, so this is a plain hasMany. */
    public function guides()
    {
        return $this->hasMany(UserGuide::class);
    }

    public function hasMember(?User $user): bool
    {
        return $user !== null && $this->members()->whereKey($user->id)->exists();
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $user->id === $this->owner_id;
    }

    /**
     * Add someone, idempotently. Re-joining is a no-op rather than an error: the invite is a URL
     * that will be clicked twice.
     */
    public function join(User $user): void
    {
        if (! $this->hasMember($user)) {
            $this->members()->attach($user->id, [
                'role' => $this->isOwnedBy($user) ? GuildRole::Owner->value : GuildRole::Member->value,
            ]);
        }
    }

    /**
     * Remove someone. THE OWNER CANNOT LEAVE their own guild — a guild with no owner has nobody
     * who can remove members or delete it, and silently promoting an arbitrary member would be a
     * surprising thing to do to them. Deleting the guild is the way out.
     */
    public function leave(User $user): bool
    {
        if ($this->isOwnedBy($user)) {
            return false;
        }

        $this->members()->detach($user->id);

        return true;
    }
}
