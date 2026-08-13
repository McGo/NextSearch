<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tag hanging on a document. Modelled as its own row rather than a plain
 * pivot, because it carries who assigned it, where it came from and when it
 * runs out.
 */
#[Fillable(['document_id', 'tag_id', 'user_id', 'assigned_by_id', 'source', 'expires_at'])]
class TagAssignment extends Model
{
    protected $table = 'document_tag';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO = 'auto';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * Assignments $user may see: everything global, plus their own personal
     * ones. Someone else's personal assignment does not exist for them.
     *
     * @param  Builder<TagAssignment>  $query
     * @return Builder<TagAssignment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('user_id')->orWhere('user_id', $user->id);
        });
    }
}
