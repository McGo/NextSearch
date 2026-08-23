<?php

namespace App\Services\Tags;

use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Tagging many documents in one go, and turning the slugs a caller wrote into
 * the tags they meant.
 *
 * Three surfaces share this: the interface, /api/v1 and the MCP server. They
 * differ in how they are authenticated and in nothing else — a rule that only
 * held on two of the three would be the kind that gets found out later.
 */
class BulkTagger
{
    /** Documents one call may touch. */
    public const LIMIT = 500;

    public function __construct(private readonly TagService $tags) {}

    /**
     * Turns slugs or uuids into tags. Anything unknown or off limits stops the
     * call — silently dropping a tag would leave the caller believing it landed.
     *
     * @param  list<string>  $keys
     * @return Collection<int, Tag>
     */
    public function lookup(array $keys, User $user): Collection
    {
        return collect($keys)->unique()->values()->map(function (string $key) use ($user) {
            $tag = $this->tags->resolve($key, $user);

            if ($tag === null) {
                throw ValidationException::withMessages([
                    'tags' => __('nextsearch.tag.unknown_named', ['tag' => $key]),
                ]);
            }

            if (! $tag->assignableBy($user)) {
                throw ValidationException::withMessages([
                    'tags' => __('nextsearch.tag.not_assignable_named', ['tag' => $key]),
                ]);
            }

            return $tag;
        });
    }

    /**
     * Hangs `$add` on every given document and takes `$remove` off again.
     *
     * Documents the caller has no access to are skipped rather than refused — a
     * bulk call over a wide result set should not fail on a single folder.
     *
     * @param  list<string>  $uuids
     * @param  Collection<int, Tag>  $add
     * @param  Collection<int, Tag>  $remove
     * @return array{updated: int, skipped: list<string>, unknown: list<string>}
     */
    public function apply(User $user, array $uuids, Collection $add, Collection $remove): array
    {
        if ($add->isEmpty() && $remove->isEmpty()) {
            throw ValidationException::withMessages(['add' => __('nextsearch.tag.nothing_to_do')]);
        }

        $documents = Document::query()->whereIn('uuid', $uuids)->get();

        $updated = 0;
        $skipped = [];

        foreach ($documents as $document) {
            if (! $user->canAccessFolder($document->watched_folder_id)) {
                $skipped[] = $document->uuid;

                continue;
            }

            foreach ($add as $tag) {
                $this->tags->attach($document, $tag, $user);
            }

            foreach ($remove as $tag) {
                $this->tags->detach($document, $tag, $user);
            }

            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            // Uuids that matched no document at all — a typo or a deleted file.
            'unknown' => array_values(array_diff($uuids, $documents->pluck('uuid')->all())),
        ];
    }
}
