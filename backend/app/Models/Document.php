<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'watched_folder_id', 'nextcloud_instance_id',
    'oc_file_id', 'remote_path', 'path_hash', 'name', 'extension', 'mime_type',
    'size', 'remote_modified_at', 'etag', 'text_key', 'preview_key', 'page_count',
    'ocr_used', 'metadata', 'state', 'failure_reason', 'attempts', 'indexed_at',
])]
class Document extends Model
{
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_INDEXED = 'indexed';

    public const STATE_FAILED = 'failed';

    /** Format bewusst nicht unterstützt oder Datei zu groß. */
    public const STATE_SKIPPED = 'skipped';

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
            'remote_modified_at' => 'datetime',
            'indexed_at' => 'datetime',
            'metadata' => 'array',
            'ocr_used' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WatchedFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class, 'watched_folder_id');
    }

    /**
     * @return BelongsTo<NextcloudInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(NextcloudInstance::class, 'nextcloud_instance_id');
    }

    public static function hashPath(string $path): string
    {
        return hash('sha256', trim($path, '/'));
    }

    /**
     * Unverändert heißt: gleiche ETag und gleiche Größe. Die ETag allein würde
     * reichen, die Größe kostet nichts und fängt Sonderfälle ab.
     */
    public function matchesRemote(?string $etag, int $size): bool
    {
        return $this->state === self::STATE_INDEXED
            && $this->etag !== null
            && $this->etag === $etag
            && $this->size === $size;
    }
}
