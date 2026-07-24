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
            // `nullable`, weil Laravels ConvertEmptyStringsToNull aus einem leeren
            // Suchfeld ein null macht — das ist keine ungültige Eingabe.
            'q' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort' => ['sometimes', 'string', 'in:relevance,newest,oldest,largest,name'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('nextsearch.search.max_per_page')],
            // Die Facettenfilter kommen als JSON-String — so serialisiert das
            // Frontend das verschachtelte Objekt in den Query-String.
            'filters' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        // Der Ordnerfilter wird im Dienst gesetzt, nicht hier — er darf nicht
        // von etwas abhängen, das aus dem Request stammt.
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
     * Dekodiert den Filter-JSON zu `facet => list<string>`. Unbekannte Facetten
     * fängt der Suchdienst ab; hier wird nur die Form gesäubert, damit nichts
     * anderes als Zeichenketten durchkommt.
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
