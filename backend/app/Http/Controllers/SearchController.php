<?php

namespace App\Http\Controllers;

use App\Services\Search\DocumentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly DocumentSearch $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `nullable`, because Laravel's ConvertEmptyStringsToNull turns an
            // empty search field into null — that's not invalid input.
            'q' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort' => ['sometimes', 'string', 'in:relevance,newest,oldest,largest,name'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('nextsearch.search.max_per_page')],
            // The facet filters arrive as a JSON string — that's how the
            // frontend serialises the nested object into the query string.
            'filters' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        // The folder filter is set in the service, not here — it must not
        // depend on anything that comes from the request.
        $result = $this->search->search(
            user: $request->user(),
            query: $validated['q'] ?? '',
            filters: $this->parseFilters($validated['filters'] ?? null),
            sort: $validated['sort'] ?? 'relevance',
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? config('nextsearch.search.per_page')),
        );

        return response()->json($result);
    }

    /**
     * Decodes the filter JSON into `facet => list<string>`. Unknown facets are
     * caught by the search service; here only the shape is cleaned so nothing
     * other than strings gets through.
     *
     * @return array<string, list<string>>
     */
    private function parseFilters(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $filters = [];

        foreach ($decoded as $facet => $values) {
            if (! is_string($facet) || ! is_array($values)) {
                continue;
            }

            $strings = array_values(array_filter(
                $values,
                fn ($value) => is_string($value) && $value !== '',
            ));

            if ($strings !== []) {
                $filters[$facet] = $strings;
            }
        }

        return $filters;
    }
}
