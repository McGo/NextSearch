<?php

namespace App\Services\Tags;

use App\Models\Document;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything that happens between a tag and a document.
 *
 * The database holds the truth; the search index only mirrors it as
 * `tag_tokens`. Every write therefore ends by pushing the affected documents
 * back into the index — see syncIndex().
 */
class TagService
{
    /** Documents pushed into the index per request. */
    private const CHUNK = 500;

    public function __construct(private readonly SearchIndex $index) {}

    /**
     * Looks a tag up by uuid or slug, within what this user may use. Slugs are
     * unique per owner, so an instance tag and someone's own tag can carry the
     * same one — their own wins, being the more specific.
     *
     * The uuid column is only compared when the key looks like one: Postgres
     * refuses `uuid = 'rechnung'` outright rather than returning no row.
     */
    public function resolve(string $key, User $user, bool $onlyEnabled = true): ?Tag
    {
        return Tag::query()
            ->visibleTo($user)
            ->when($onlyEnabled, fn ($q) => $q->where('enabled', true))
            ->where(function ($q) use ($key) {
                $q->where('slug', $key);

                if (Str::isUuid($key)) {
                    $q->orWhere('uuid', $key);
                }
            })
            ->orderByRaw('CASE WHEN owner_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /**
     * Deletes a tag and takes its assignments out of the index.
     *
     * Order matters: the documents are noted first, because the assignments go
     * with the tag and afterwards there is nothing left to find them by.
     */
    public function forget(Tag $tag): void
    {
        $documentIds = TagAssignment::query()
            ->where('tag_id', $tag->id)
            ->pluck('document_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $tag->delete();

        $this->syncIndex($documentIds);
    }

    /**
     * The vocabulary this user may work with: the instance tags plus their own
     * private ones.
     *
     * @return Collection<int, Tag>
     */
    public function visibleTo(User $user, bool $onlyEnabled = true): Collection
    {
        return Tag::query()
            ->visibleTo($user)
            ->when($onlyEnabled, fn ($q) => $q->where('enabled', true))
            ->orderBy('position')
            ->orderBy('slug')
            ->get();
    }

    /**
     * Index token => tag, for everything this user may see. The facets and the
     * tags on a hit are resolved through this map — anything not in it belongs
     * to someone else and never leaves the backend.
     *
     * @return array<string, Tag>
     */
    public function tokenIndex(User $user): array
    {
        $map = [];

        foreach ($this->visibleTo($user) as $tag) {
            $map[$tag->token($tag->isPersonal() ? $user->id : null)] = $tag;
        }

        return $map;
    }

    /**
     * The tokens of one document — what goes into the index. Assignments of
     * disabled tags and expired ones are left out; they are not visible, so
     * they must not be filterable either.
     *
     * @return list<string>
     */
    public function tokensFor(Document $document): array
    {
        return $this->tokenMap([$document->id])[$document->id] ?? [];
    }

    /**
     * @param  list<int>  $documentIds
     * @return array<int, list<string>>
     */
    public function tokenMap(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = TagAssignment::query()
            ->join('tags', 'tags.id', '=', 'document_tag.tag_id')
            ->whereIn('document_tag.document_id', $documentIds)
            ->where('tags.enabled', true)
            ->where(fn ($q) => $q
                ->whereNull('document_tag.expires_at')
                ->orWhere('document_tag.expires_at', '>', now()))
            ->get(['document_tag.document_id', 'document_tag.tag_id', 'document_tag.user_id']);

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->document_id][] = Tag::tokenFor(
                (int) $row->tag_id,
                $row->user_id === null ? null : (int) $row->user_id,
            );
        }

        return $map;
    }

    /**
     * Writes the current tokens of the given documents into the search index.
     *
     * Only indexed documents are touched: a partial update on an unknown id
     * would leave Meilisearch with a stub carrying nothing but an id and tags.
     *
     * @param  list<int>  $documentIds
     */
    public function syncIndex(array $documentIds): void
    {
        $documentIds = array_values(array_unique(array_filter($documentIds)));

        foreach (array_chunk($documentIds, self::CHUNK) as $chunk) {
            $uuids = Document::query()
                ->whereIn('id', $chunk)
                ->where('state', Document::STATE_INDEXED)
                ->pluck('uuid', 'id');

            if ($uuids->isEmpty()) {
                continue;
            }

            $tokens = $this->tokenMap($uuids->keys()->map(fn ($id) => (int) $id)->all());

            $this->index->patch($uuids->map(fn (string $uuid, $id) => [
                'id' => str_replace('-', '', $uuid),
                'tag_tokens' => $tokens[(int) $id] ?? [],
            ])->values()->all());
        }
    }

    /**
     * Every document carrying this tag, back into the index. Needed when a tag
     * is switched off or deleted — its assignments then disappear from the
     * filter without anyone touching the documents themselves.
     */
    public function syncTagDocuments(Tag $tag): void
    {
        TagAssignment::query()
            ->where('tag_id', $tag->id)
            ->select(['id', 'document_id'])
            ->chunkById(self::CHUNK, function (Collection $rows) {
                $this->syncIndex($rows->map(fn ($row) => (int) $row->document_id)->all());
            });
    }

    /**
     * Hangs a tag on a document. Whether the assignment is global or the
     * actor's own follows from the tag's scope, not from the caller.
     */
    public function attach(Document $document, Tag $tag, User $actor): void
    {
        TagAssignment::query()->updateOrCreate(
            [
                'document_id' => $document->id,
                'tag_id' => $tag->id,
                'user_id' => $tag->isPersonal() ? $actor->id : null,
            ],
            [
                'assigned_by_id' => $actor->id,
                'source' => TagAssignment::SOURCE_MANUAL,
                // A manual assignment does not run out — not even when it
                // replaces one the automation made.
                'expires_at' => null,
            ],
        );

        $this->syncIndex([$document->id]);
    }

    /**
     * Removes a tag from a document. A personal tag only ever loses the actor's
     * own assignment; other people's stay where they are.
     */
    public function detach(Document $document, Tag $tag, User $actor): void
    {
        TagAssignment::query()
            ->where('document_id', $document->id)
            ->where('tag_id', $tag->id)
            ->when(
                $tag->isPersonal(),
                fn ($q) => $q->where('user_id', $actor->id),
                fn ($q) => $q->whereNull('user_id'),
            )
            ->delete();

        $this->syncIndex([$document->id]);
    }

    /**
     * The tags on a document as this user sees them.
     *
     * @return list<array<string, mixed>>
     */
    public function present(Document $document, User $user): array
    {
        $tokens = $this->tokenIndex($user);

        $rows = TagAssignment::query()
            ->where('document_id', $document->id)
            ->visibleTo($user)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $tags = [];

        foreach ($rows as $row) {
            $token = Tag::tokenFor((int) $row->tag_id, $row->user_id === null ? null : (int) $row->user_id);
            $tag = $tokens[$token] ?? null;

            // Not in the map means disabled or not this user's business.
            if ($tag === null) {
                continue;
            }

            $tags[] = $tag->presentBrief() + [
                'source' => $row->source,
                'expires_at' => $row->expires_at?->toIso8601String(),
            ];
        }

        return $tags;
    }

    /**
     * Hangs the automatic tags on a freshly indexed document.
     *
     * Nothing happens while the folder has no baseline: on the first crawl the
     * whole stock is new, and "Neu" on twenty thousand documents is no filter.
     * IndexRun::complete() sets the baseline, so the automation starts with the
     * second run.
     */
    public function applyAutoTags(Document $document): void
    {
        $document->loadMissing('folder');

        if ($document->folder?->baseline_at === null) {
            return;
        }

        $tags = Tag::query()
            ->where('enabled', true)
            ->where('auto_rule', Tag::AUTO_NEW_DOCUMENT)
            ->get();

        foreach ($tags as $tag) {
            TagAssignment::query()->updateOrCreate(
                [
                    'document_id' => $document->id,
                    'tag_id' => $tag->id,
                    // Automatic assignments belong to nobody in particular, so
                    // they are global regardless of the tag's scope.
                    'user_id' => null,
                ],
                [
                    'assigned_by_id' => null,
                    'source' => TagAssignment::SOURCE_AUTO,
                    'expires_at' => $tag->auto_duration_minutes === null
                        ? null
                        : now()->addMinutes($tag->auto_duration_minutes),
                ],
            );
        }
    }

    /**
     * Drops the assignments that have run out and pushes their documents back
     * into the index. Returns how many rows went.
     */
    public function sweepExpired(): int
    {
        $removed = 0;

        do {
            $rows = TagAssignment::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->limit(self::CHUNK)
                ->get(['id', 'document_id']);

            if ($rows->isEmpty()) {
                break;
            }

            TagAssignment::query()->whereIn('id', $rows->pluck('id'))->delete();
            $this->syncIndex($rows->map(fn ($row) => (int) $row->document_id)->all());

            $removed += $rows->count();
        } while ($rows->count() === self::CHUNK);

        return $removed;
    }
}
