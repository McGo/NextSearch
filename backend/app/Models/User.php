<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'invitation_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    private const INVITATION_RANDOM_LENGTH = 48;

    /**
     * The uuid sits next to the auto-increment id, it does not replace it.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Addressed by uuid from the outside — in the admin interface as in the
     * admin API.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disabled_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * 2FA gates the login only once it's confirmed — a half-finished enrolment
     * (secret set, never verified) must not lock anyone out.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Folders shared with this user in NextSearch. This has nothing to do with
     * the file permissions in Nextcloud — see docs/permissions.md.
     *
     * @return BelongsToMany<WatchedFolder, $this>
     */
    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(WatchedFolder::class, 'folder_user')
            ->withTimestamps();
    }

    /**
     * The searches this user has saved for later.
     *
     * @return HasMany<SavedSearch, $this>
     */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }

    /**
     * The private tags this user created for themselves. The instance
     * vocabulary is not in here — see Tag::scopeVisibleTo().
     *
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'owner_id');
    }

    /**
     * @return HasMany<ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * A disabled account keeps its tags, saved searches and keys, but nothing
     * gets in with it: no sign-in, no API key, no OAuth token.
     */
    public function isDisabled(): bool
    {
        // Read from the raw attributes: a freshly created model has no
        // `disabled_at` until it is reloaded, and strict mode would throw.
        return ($this->attributes['disabled_at'] ?? null) !== null;
    }

    /**
     * Creates a fresh invitation link and returns its plain token. Only the
     * hash is kept, so an earlier link stops working the moment a new one is
     * issued. NextSearch sends no mail — the caller passes the link on.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function issueInvitation(): array
    {
        $plain = Str::random(self::INVITATION_RANDOM_LENGTH);
        $expiresAt = now()->addHours((int) config('nextsearch.invitations.expire_hours'));

        $this->forceFill([
            'invitation_hash' => self::hashInvitation($plain),
            'invitation_expires_at' => $expiresAt,
        ])->save();

        return ['token' => $plain, 'expires_at' => $expiresAt];
    }

    public static function hashInvitation(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The account a still-valid invitation belongs to. Expired links and
     * disabled accounts count as unknown.
     */
    public static function findByInvitation(string $plain): ?self
    {
        $user = self::query()->where('invitation_hash', self::hashInvitation($plain))->first();

        if ($user === null || $user->isDisabled() || ! $user->hasPendingInvitation()) {
            return null;
        }

        return $user;
    }

    public function hasPendingInvitation(): bool
    {
        return ($this->attributes['invitation_hash'] ?? null) !== null
            && $this->invitation_expires_at?->isFuture() === true;
    }

    public static function invitationUrl(string $plain): string
    {
        return rtrim((string) config('app.url'), '/').'/invite/'.$plain;
    }

    /**
     * IDs of every folder this user may search. `null` means "no restriction"
     * and applies only to administrators.
     *
     * @return Collection<int, int>|null
     */
    public function accessibleFolderIds(): ?Collection
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->folders()
            ->where('watched_folders.enabled', true)
            ->pluck('watched_folders.id');
    }

    public function canAccessFolder(int $folderId): bool
    {
        $allowed = $this->accessibleFolderIds();

        return $allowed === null || $allowed->contains($folderId);
    }
}
