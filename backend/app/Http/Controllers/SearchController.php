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
            'q' => ['sometimes', 'string', 'max:500'],
            'sort' => ['sometimes', 'string', 'in:relevance,newest,oldest,largest,name'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('nextsearch.search.max_per_page')],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['array'],
            'filters.*.*' => ['string', 'max:200'],
        ]);

        // Der Ordnerfilter wird im Dienst gesetzt, nicht hier — er darf nicht
        // von etwas abhängen, das aus dem Request stammt.
        $result = $this->search->search(
            user: $request->user(),
            query: $validated['q'] ?? '',
            filters: $validated['filters'] ?? [],
            sort: $validated['sort'] ?? 'relevance',
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? config('nextsearch.search.per_page')),
        );

        return response()->json($result);
    }
}
