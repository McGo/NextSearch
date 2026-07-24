<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'base_url', 'username', 'app_password', 'verify_tls', 'enabled'])]
#[Hidden(['app_password'])]
class NextcloudInstance extends Model
{
    use HasFactory, HasUuids;

    public const HEALTH_OK = 'ok';

    public const HEALTH_FAILED = 'failed';

    public const HEALTH_UNKNOWN = 'unknown';

    /**
     * `uuid` is the outward-facing identifier; the primary key stays the
     * autoincrement id.
     *
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
            // The app password is stored only encrypted. If the APP_KEY is
            // lost, the instances have to be re-entered.
            'app_password' => 'encrypted',
            'verify_tls' => 'boolean',
            'enabled' => 'boolean',
            'health_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WatchedFolder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(WatchedFolder::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * WebDAV root of the instance user, without a trailing slash.
     */
    public function davRoot(): string
    {
        return rtrim($this->base_url, '/')
            .'/remote.php/dav/files/'
            .rawurlencode($this->username);
    }

    public function imageUrl(): ?string
    {
        return $this->image_key === null ? null : "/api/instances/{$this->uuid}/image";
    }
}
