<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the tag vocabulary.
 *
 * Two things are easily confused and mean different things: `owner_id` says who
 * maintains the tag, `scope` says who sees an assignment. An administrator can
 * define an instance-wide tag whose assignments stay private to whoever made
 * them — that is `owner_id = null` with `scope = personal`.
 */
#[Fillable([
    'slug', 'name', 'color', 'description', 'scope', 'assign_policy',
    'enabled', 'position', 'auto_rule', 'auto_duration_minutes',
])]
class Tag extends Model
{
    use HasFactory, HasUuids;

    /** Assignments apply to every user of the installation. */
    public const SCOPE_GLOBAL = 'global';

    /** Assignments apply only to the user who made them. */
    public const SCOPE_PERSONAL = 'personal';

    public const POLICY_EVERYONE = 'everyone';

    public const POLICY_ADMIN = 'admin';

    /** Hangs on every document indexed for the first time. */
    public const AUTO_NEW_DOCUMENT = 'new_document';

    /** Slug of the system tag shipped with the installation. */
    public const SLUG_NEW = 'new';

    /**
     * The palette a tag may use. Deliberately names, not hex values: the
     * interface maps them onto its own theme, so a tag looks right in light and
     * dark mode without anyone picking two colours.
     */
    public const COLORS = ['neutral', 'primary', 'secondary', 'success', 'info', 'warning', 'error'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<TagAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TagAssignment::class);
    }

    /**
     * Tags this user may see and use: the instance vocabulary plus their own.
     *
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('owner_id')->orWhere('owner_id', $user->id);
        });
    }

    public function isPersonal(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    /** A tag someone created for themselves — never global, never automatic. */
    public function isPrivate(): bool
    {
        return $this->owner_id !== null;
    }

    /**
     * The value this tag takes in the search index. A global assignment is the
     * same for everyone, a personal one carries the user it belongs to.
     *
     * Keyed by id, not by slug: an administrator's personal-scope "wichtig" and
     * a user's own "wichtig" would otherwise collide, and a rename would force
     * a reindex.
     */
    public function token(?int $userId = null): string
    {
        return self::tokenFor($this->id, $userId);
    }

    /**
     * The same, built from raw ids — for the bulk queries that never load a
     * model.
     */
    public static function tokenFor(int $tagId, ?int $userId): string
    {
        return ($userId === null ? '' : 'u'.$userId.':').'t'.$tagId;
    }

    /**
     * Whether $user may hang this tag on a document they have access to. The
     * document access itself is checked by the caller.
     */
    public function assignableBy(User $user): bool
    {
        if ($this->isPrivate()) {
            return $this->owner_id === $user->id;
        }

        if ($this->isPersonal()) {
            return true;
        }

        return $this->assign_policy !== self::POLICY_ADMIN || $user->isAdmin();
    }

    /**
     * Whether $user may edit or delete the tag itself. System tags stay put; how
     * much of one an administrator may change is decided in the controller.
     */
    public function editableBy(User $user): bool
    {
        return $this->isPrivate() ? $this->owner_id === $user->id : $user->isAdmin();
    }

    /**
     * The shape the interface and the API consume. `name` stays null for system
     * tags — the client translates those from the slug.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description,
            'scope' => $this->scope,
            'assign_policy' => $this->assign_policy,
            'is_system' => $this->is_system,
            'is_private' => $this->isPrivate(),
            'enabled' => $this->enabled,
            'position' => $this->position,
            'auto_rule' => $this->auto_rule,
            'auto_duration_minutes' => $this->auto_duration_minutes,
        ];
    }

    /**
     * The short form that travels with a search hit — enough to draw a chip.
     *
     * @return array<string, mixed>
     */
    public function presentBrief(): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'scope' => $this->scope,
            'is_system' => $this->is_system,
        ];
    }
}
