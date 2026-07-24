<?php

namespace App\Http\Controllers;

use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A user's own saved searches. Everything here is scoped to the signed-in user
 * — a saved search belongs to whoever created it and to no one else.
 */
class SavedSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $searches = $request->user()->savedSearches()
            ->latest('id')
            ->get()
            ->map(fn (SavedSearch $s) => $s->present());

        return response()->json(['data' => $searches]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'query' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort' => ['sometimes', 'string', 'in:relevance,newest,oldest,largest,name'],
            // The interface sends the facet selection as a plain object.
            'filters' => ['sometimes', 'nullable', 'array'],
        ]);

        $search = $request->user()->savedSearches()->create([
            'name' => $validated['name'],
            'query' => $validated['query'] ?? null,
            'filters' => $this->cleanFilters($validated['filters'] ?? []),
            'sort' => $validated['sort'] ?? 'relevance',
        ]);

        return response()->json($search->present(), Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $savedSearch): Response
    {
        // Look the search up within the user's own — an id that isn't theirs
        // simply doesn't exist, rather than leaking that it's someone else's.
        $request->user()->savedSearches()
            ->where('uuid', $savedSearch)
            ->firstOrFail()
            ->delete();

        return response()->noContent();
    }

    /**
     * Keep only `facet => list<string>`; drop anything else so nothing odd ends
     * up stored. The search service ignores unknown facets on recall anyway.
     *
     * @param  array<mixed>  $filters
     * @return array<string, list<string>>
     */
    private function cleanFilters(array $filters): array
    {
        $clean = [];

        foreach ($filters as $facet => $values) {
            if (! is_string($facet) || ! is_array($values)) {
                continue;
            }

            $strings = array_values(array_filter(
                $values,
                fn ($value) => is_string($value) && $value !== '',
            ));

            if ($strings !== []) {
                $clean[$facet] = $strings;
            }
        }

        return $clean;
    }
}
