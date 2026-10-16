<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'watched_folder_id', 'nextcloud_instance_id',
    'oc_file_id', 'remote_path', 'path_hash', 'name', 'extension', 'mime_type',
    'size', 'remote_modified_at', 'etag', 'text_key', 'preview_key', 'page_count',
    'ocr_used', 'metadata', 'state', 'failure_reason', 'attempts', 'indexed_at',
    'first_indexed_at', 'vanished_at',
])]
class Document extends Model
{
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_INDEXED = 'indexed';

    public const STATE_FAILED = 'failed';

    /** Format deliberately unsupported or file too large. */
    public const STATE_SKIPPED = 'skipped';

    /** Name of the global scope that hides vanished documents. */
    public const SCOPE_PRESENT = 'present';

    /**
     * A vanished document — gone from its directory, not yet found again
     * elsewhere — is invisible to everything except the crawl and the purge.
     * It is out of the search index, and to the rest of the application it
     * does not exist.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(
            self::SCOPE_PRESENT,
            fn (Builder $query) => $query->whereNull($query->qualifyColumn('vanished_at')),
        );
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeWithVanished(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::SCOPE_PRESENT);
    }

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
            'first_indexed_at' => 'datetime',
            'vanished_at' => 'datetime',
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

    /**
     * Every tag hanging on this document — global and personal ones alike.
     * Filtering by visibility is the caller's business.
     *
     * @return HasMany<TagAssignment, $this>
     */
    public function tagAssignments(): HasMany
    {
        return $this->hasMany(TagAssignment::class);
    }

    public static function hashPath(string $path): string
    {
        return hash('sha256', trim($path, '/'));
    }

    /**
     * Takes the document out of circulation without deleting it. The path is
     * given up — a different file may appear there in the meantime — but the
     * uuid, the tags and the file id stay, so a crawl that finds the file
     * elsewhere can bring it back. Removing it from the search index is the
     * caller's business.
     */
    public function markVanished(): void
    {
        $this->forceFill([
            'vanished_at' => now(),
            'path_hash' => self::hashPath('vanished:'.$this->uuid),
        ])->save();
    }

    public function isVanished(): bool
    {
        return ($this->attributes['vanished_at'] ?? null) !== null;
    }

    /**
     * Unchanged means: same ETag and same size. The ETag alone would do; the
     * size costs nothing and catches edge cases.
     */
    public function matchesRemote(?string $etag, int $size): bool
    {
        return $this->state === self::STATE_INDEXED
            && $this->etag !== null
            && $this->etag === $etag
            && $this->size === $size;
    }
}
