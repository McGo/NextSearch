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
     * `uuid` ist die nach außen sichtbare Kennung, der Primärschlüssel bleibt
     * die Autoincrement-ID.
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
            // Das App-Passwort liegt nur verschlüsselt in der Datenbank. Geht
            // der APP_KEY verloren, müssen die Instanzen neu hinterlegt werden.
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
     * WebDAV-Wurzel des Instanz-Benutzers, ohne abschließenden Slash.
     */
    public function davRoot(): string
    {
        return rtrim($this->base_url, '/')
            .'/remote.php/dav/files/'
            .rawurlencode($this->username);
    }
}
