<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The tag vocabulary. Two kinds live here and are treated differently:
 *
 * - instance tags (`owner_id` null) belong to the installation and only an
 *   administrator maintains them;
 * - private tags belong to the user who created them and to nobody else.
 *
 * The same controller answers the interface over the session and /api/v1 over a
 * personal key — one code path, one response shape.
 */
class TagController extends Controller
{
    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Disabled tags are of interest to whoever administers them; for
        // everyone else they don't exist.
        $withDisabled = $request->boolean('include_disabled') && $user->isAdmin();

        return response()->json([
            'data' => $this->tags->visibleTo($user, onlyEnabled: ! $withDisabled)
                ->map(fn (Tag $tag) => $tag->present())
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $this->validatePayload($request);

        // Anyone may create a tag for themselves. Only an administrator may add
        // one to the installation's vocabulary.
        $instanceWide = ($validated['owner'] ?? 'me') === 'instance';

        if ($instanceWide && ! $user->isAdmin()) {
            abort(403, __('nextsearch.tag.admin_only'));
        }

        $attributes = $instanceWide
            ? $this->instanceAttributes($validated)
            : $this->privateAttributes($user);

        $name = $validated['name'];
        $slug = $this->uniqueSlug(
            // The current language decides the transliteration — "Später" is
            // `spaeter` for a German instance, `spater` for an English one.
            $validated['slug'] ?? Str::slug($name, '-', app()->getLocale()),
            $instanceWide ? null : $user->id,
        );

        $tag = new Tag;
        $tag->forceFill($attributes + [
            'slug' => $slug,
            'name' => $name,
            'color' => $validated['color'] ?? 'neutral',
            'description' => $validated['description'] ?? null,
            // Only the bootstrap command creates system tags.
            'is_system' => false,
            'enabled' => $validated['enabled'] ?? true,
            'position' => $validated['position'] ?? 0,
        ])->save();

        return response()->json($tag->present(), Response::HTTP_CREATED);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->visible($tag, $user) && $tag->editableBy($user), 404);

        $validated = $this->validatePayload($request, updating: true);
        $changes = [];

        foreach (['name', 'color', 'description', 'position', 'enabled'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        // A system tag keeps its identity: slug, scope and the fact that it
        // assigns itself are not up for discussion. Its name, colour, duration
        // and on/off switch are.
        if (! $tag->is_system && ! $tag->isPrivate()) {
            foreach (['assign_policy', 'auto_rule'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = $validated[$field];
                }
            }
        }

        if (array_key_exists('auto_duration_minutes', $validated) && ! $tag->isPrivate()) {
            $changes['auto_duration_minutes'] = $validated['auto_duration_minutes'];
        }

        // The scope decides who has been seeing which assignment. Changing it
        // afterwards would rewrite that history — so it stays as created.
        if (isset($validated['scope']) && $validated['scope'] !== $tag->scope) {
            throw ValidationException::withMessages([
                'scope' => __('nextsearch.tag.scope_immutable'),
            ]);
        }

        if (($changes['auto_rule'] ?? $tag->auto_rule) !== null && $tag->isPersonal()) {
            throw ValidationException::withMessages([
                'auto_rule' => __('nextsearch.tag.auto_needs_global'),
            ]);
        }

        $wasEnabled = $tag->enabled;
        $tag->forceFill($changes)->save();

        // Switching a tag off takes its assignments out of the filter without
        // touching a single document row.
        if ($wasEnabled !== $tag->enabled) {
            $this->tags->syncTagDocuments($tag);
        }

        return response()->json($tag->present());
    }

    public function destroy(Request $request, Tag $tag): Response
    {
        $user = $request->user();

        abort_unless($this->visible($tag, $user) && $tag->editableBy($user), 404);
        abort_if($tag->is_system, 403, __('nextsearch.tag.system_protected'));

        $this->tags->forget($tag);

        return response()->noContent();
    }

    /**
     * A tag nobody may see doesn't exist for them — 404 rather than 403, so an
     * id says nothing about whether it belongs to someone else.
     */
    private function visible(Tag $tag, User $user): bool
    {
        return $tag->owner_id === null || $tag->owner_id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'color' => ['sometimes', 'string', Rule::in(Tag::COLORS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'owner' => ['sometimes', Rule::in(['me', 'instance'])],
            'scope' => ['sometimes', Rule::in([Tag::SCOPE_GLOBAL, Tag::SCOPE_PERSONAL])],
            'assign_policy' => ['sometimes', Rule::in([Tag::POLICY_EVERYONE, Tag::POLICY_ADMIN])],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'enabled' => ['sometimes', 'boolean'],
            'auto_rule' => ['sometimes', 'nullable', Rule::in([Tag::AUTO_NEW_DOCUMENT])],
            // Up to a year — beyond that "automatic" stops meaning anything.
            'auto_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function instanceAttributes(array $validated): array
    {
        $scope = $validated['scope'] ?? Tag::SCOPE_GLOBAL;
        $autoRule = $validated['auto_rule'] ?? null;

        // An automatic assignment belongs to nobody in particular, so it can
        // only ever be global.
        if ($autoRule !== null && $scope !== Tag::SCOPE_GLOBAL) {
            throw ValidationException::withMessages([
                'auto_rule' => __('nextsearch.tag.auto_needs_global'),
            ]);
        }

        return [
            'owner_id' => null,
            'scope' => $scope,
            'assign_policy' => $validated['assign_policy'] ?? Tag::POLICY_EVERYONE,
            'auto_rule' => $autoRule,
            'auto_duration_minutes' => $validated['auto_duration_minutes'] ?? null,
        ];
    }

    /**
     * A tag someone made for themselves. Always personal, never automatic —
     * anything else would let it reach other people.
     *
     * @return array<string, mixed>
     */
    private function privateAttributes(User $user): array
    {
        return [
            'owner_id' => $user->id,
            'scope' => Tag::SCOPE_PERSONAL,
            'assign_policy' => Tag::POLICY_EVERYONE,
            'auto_rule' => null,
            'auto_duration_minutes' => null,
        ];
    }

    /**
     * Slugs are unique per owner. A collision gets a counter rather than an
     * error — the name is what people read, the slug is plumbing.
     */
    private function uniqueSlug(string $base, ?int $ownerId): string
    {
        $base = $base === '' ? 'tag' : mb_substr($base, 0, 60);
        $slug = $base;
        $suffix = 2;

        while (Tag::query()
            ->where('slug', $slug)
            ->when($ownerId === null,
                fn ($q) => $q->whereNull('owner_id'),
                fn ($q) => $q->where('owner_id', $ownerId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
