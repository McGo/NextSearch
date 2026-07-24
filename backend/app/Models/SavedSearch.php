<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'query', 'filters', 'sort'])]
class SavedSearch extends Model
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
            'filters' => 'array',
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
     * The shape the interface consumes to list and recall a search.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'query' => $this->query ?? '',
            'filters' => (object) ($this->filters ?? []),
            'sort' => $this->sort,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
