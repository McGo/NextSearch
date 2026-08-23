<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\BulkTagger;
use App\Services\Tags\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
    public function __construct(
        private readonly TagService $tags,
        private readonly BulkTagger $bulk,
    ) {}

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
     * The work itself sits in BulkTagger — the MCP server runs the same code.
     */
    public function bulk(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:'.BulkTagger::LIMIT],
            'documents.*' => ['string', 'uuid'],
            'add' => ['sometimes', 'array', 'max:50'],
            'add.*' => ['string', 'max:64'],
            'remove' => ['sometimes', 'array', 'max:50'],
            'remove.*' => ['string', 'max:64'],
        ]);

        return response()->json($this->bulk->apply(
            $user,
            $validated['documents'],
            $this->bulk->lookup($validated['add'] ?? [], $user),
            $this->bulk->lookup($validated['remove'] ?? [], $user),
        ));
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

        return $this->bulk->lookup($validated['tags'], $user);
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
