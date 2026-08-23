<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keeps a connection alive past the access token's hour. Rotated on every use:
 * the old one is revoked as the new one is handed out, so a stolen refresh
 * token is worth one request at most — and the theft shows up as a failure on
 * the legitimate client's next refresh.
 */
class OauthRefreshToken extends Model
{
    public const PREFIX = 'nxr_';

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OauthClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'oauth_client_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<OauthAccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(OauthAccessToken::class, 'oauth_access_token_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
