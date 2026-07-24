<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['label', 'remote_path', 'oc_file_id', 'enabled', 'interval_minutes', 'exclude_patterns'])]
class WatchedFolder extends Model
{
    use HasFactory, HasUuids;

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
            'exclude_patterns' => 'array',
            'last_crawled_at' => 'datetime',
            'crawl_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NextcloudInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(NextcloudInstance::class, 'nextcloud_instance_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<IndexRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(IndexRun::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'folder_user')->withTimestamps();
    }

    /**
     * Pfad ohne führenden und abschließenden Slash — so wird er überall
     * zusammengesetzt.
     */
    public function normalizedPath(): string
    {
        return trim($this->remote_path, '/');
    }

    public function imageUrl(): ?string
    {
        return $this->image_key === null ? null : "/api/folders/{$this->uuid}/image";
    }

    public function isDue(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->crawl_requested_at !== null) {
            return true;
        }

        return $this->last_crawled_at === null
            || $this->last_crawled_at->addMinutes($this->interval_minutes)->isPast();
    }
}
