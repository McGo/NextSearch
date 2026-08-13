<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Hanging tags on documents and taking them off again — from the interface over
 * the session, and from /api/v1 with a personal key. Same controller either way.
 *
 * Two checks stand in front of every write: the caller must have access to the
 * document (folder shares, see docs/permissions.md) and be allowed to assign
 * the tag (its scope and assignment policy).
 */
class DocumentTagController extends Controller
{
    /** Documents one bulk call may touch. */
    private const BULK_LIMIT = 500;

    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDocument($request->user(), $document);

        return response()->json(['data' => $this->tags->present($document, $request->user())]);
    }

    /**
     * Adds tags. Anything already there stays — this is not a replacement.
     */
    public function store(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        foreach ($this->resolveTags($request, $user) as $tag) {
            $this->tags->attach($document, $tag, $user);
        }

        return response()->json(['data' => $this->tags->present($document, $user)]);
    }

    /**
     * Replaces the set with the one given. Only tags this caller could assign
     * anyway are affected — someone else's personal assignment and a tag
     * reserved for administrators stay untouched.
     */
    public function replace(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $wanted = $this->resolveTags($request, $user);
        $keep = $wanted->pluck('id')->all();

        foreach ($this->tags->present($document, $user) as $existing) {
            $tag = $this->tags->resolve($existing['uuid'], $user);

            if ($tag === null || in_array($tag->id, $keep, true) || ! $tag->assignableBy($user)) {
                continue;
            }

            $this->tags->detach($document, $tag, $user);
        }

        foreach ($wanted as $tag) {
            $this->tags->attach($document, $tag, $user);
        }

        return response()->json(['data' => $this->tags->present($document, $user)]);
    }

    public function destroy(Request $request, Document $document, string $tagKey): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $tag = $this->tags->resolve($tagKey, $user);

        abort_if($tag === null, 404, __('nextsearch.tag.unknown'));
        abort_unless($tag->assignableBy($user), 403, __('nextsearch.tag.not_assignable'));

        $this->tags->detach($document, $tag, $user);

        return response()->json(['data' => $this->tags->present($document, $user)]);
    }

    /**
     * Tagging many documents in one call. Whoever tags from a script does not
     * want one request per document.
     *
     * Documents the caller has no access to are skipped rather than refused —
     * a bulk call over a wide result set should not fail on a single folder.
     */
    public function bulk(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'documents.*' => ['string', 'uuid'],
            'add' => ['sometimes', 'array', 'max:50'],
            'add.*' => ['string', 'max:64'],
            'remove' => ['sometimes', 'array', 'max:50'],
            'remove.*' => ['string', 'max:64'],
        ]);

        $add = $this->lookup($validated['add'] ?? [], $user);
        $remove = $this->lookup($validated['remove'] ?? [], $user);

        if ($add->isEmpty() && $remove->isEmpty()) {
            throw ValidationException::withMessages(['add' => __('nextsearch.tag.nothing_to_do')]);
        }

        $documents = Document::query()
            ->whereIn('uuid', $validated['documents'])
            ->get();

        $touched = 0;
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

            $touched++;
        }

        // Uuids that matched no document at all — a typo or a deleted file.
        $unknown = array_values(array_diff($validated['documents'], $documents->pluck('uuid')->all()));

        return response()->json([
            'updated' => $touched,
            'skipped' => $skipped,
            'unknown' => $unknown,
        ]);
    }

    /**
     * @return Collection<int, Tag>
     */
    private function resolveTags(Request $request, User $user)
    {
        $validated = $request->validate([
            'tags' => ['present', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
        ]);

        return $this->lookup($validated['tags'], $user);
    }

    /**
     * Turns slugs or uuids into tags. Anything unknown or off limits stops the
     * call — silently dropping a tag would leave the caller believing it landed.
     *
     * @param  list<string>  $keys
     * @return Collection<int, Tag>
     */
    private function lookup(array $keys, User $user)
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
     * Shares are maintained solely in NextSearch; Nextcloud's file permissions
     * play no role here.
     */
    private function authorizeDocument(User $user, Document $document): void
    {
        abort_unless(
            $user->canAccessFolder($document->watched_folder_id),
            403,
            __('nextsearch.document.no_access'),
        );
    }
}
