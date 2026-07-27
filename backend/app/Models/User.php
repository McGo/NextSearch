<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
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
