<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A personal API key. It carries no permissions of its own — a request
 * authenticated with it can do exactly what its owner can do in the interface.
 */
#[Fillable(['name', 'expires_at'])]
class ApiToken extends Model
{
    use HasFactory, HasUuids;

    /** Recognisable at a glance in logs and in a `.env`. */
    public const PREFIX = 'nxs_';

    private const RANDOM_LENGTH = 40;

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
            'admin' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates a key and returns it together with its plain text. That text is
     * the only time anyone sees it — the database keeps the hash.
     *
     * @return array{token: ApiToken, plain: string}
     */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null, bool $admin = false): array
    {
        $plain = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $token = new self;
        $token->forceFill([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'last_four' => mb_substr($plain, -4),
            'admin' => $admin,
            'expires_at' => $expiresAt,
        ])->save();

        return ['token' => $token, 'plain' => $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the key opens /api/v1/admin. The flag alone is not enough: the
     * owner has to be an administrator at the moment of the request, so a key
     * loses its reach the moment its owner loses the role.
     */
    public function grantsAdminApi(): bool
    {
        return $this->admin && $this->user?->isAdmin() === true;
    }

    /**
     * A write per request would be a waste — the timestamp is precise to the
     * minute, and that is enough to tell a live key from a forgotten one.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->diffInSeconds(now(), true) < 60) {
            return;
        }

        $this->newQuery()->whereKey($this->getKey())->update(['last_used_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'last_four' => $this->last_four,
            'admin' => $this->admin,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
