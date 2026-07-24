<?php

namespace App\Services\Search;

use App\Models\User;

/**
 * Translates a search request from the interface into a Meilisearch query — and
 * enforces the user's folder filter while doing so.
 */
class DocumentSearch
{
    /** Facets the interface offers as the filter panel. */
    public const FACETS = [
        'instance_name', 'folder_label', 'extension', 'year', 'size_bucket', 'ocr_used',
    ];

    /**
     * Placeholders for match highlighting. Meilisearch inserts them raw into the
     * text; only after escaping do they become <mark> tags. The characters are
     * chosen so they practically never occur in real documents — and even then
     * they'd be harmless after escaping.
     */
    private const MARK_OPEN = "\u{2E22}NS\u{2E23}";

    private const MARK_CLOSE = "\u{2E24}NS\u{2E25}";

    private const SORTS = [
        'relevance' => null,
        'newest' => ['modified_at:desc'],
        'oldest' => ['modified_at:asc'],
        'largest' => ['size:desc'],
        'name' => ['name:asc'],
    ];

    public function __construct(private readonly SearchIndex $index) {}

    /**
     * @param  array<string, list<string>>  $filters  facet name => selected values
     * @return array<string, mixed>
     */
    public function search(
        User $user,
        string $query,
        array $filters = [],
        string $sort = 'relevance',
        int $page = 1,
        int $perPage = 20,
    ): array {
        $allowed = $user->accessibleFolderIds();

        // No shared folder means: no hits. Not "all hits".
        if ($allowed !== null && $allowed->isEmpty()) {
            return $this->emptyResult($page, $perPage);
        }

        $options = [
            'filter' => $this->buildFilter($allowed?->all(), $filters),
            'facets' => self::FACETS,
            'page' => max(1, $page),
            'hitsPerPage' => $perPage,
            'attributesToHighlight' => ['name', 'content'],
            'attributesToCrop' => ['content'],
            'cropLength' => 40,
            'cropMarker' => ' … ',
            // Bewusst keine HTML-Tags: der Ausschnitt stammt aus fremden
            // Dateiinhalten und wird erst nach dem Escapen zu Markup gemacht.
            'highlightPreTag' => self::MARK_OPEN,
            'highlightPostTag' => self::MARK_CLOSE,
            // The full text itself doesn't travel over the wire, only the
            // highlighted snippet from _formatted.
            'attributesToRetrieve' => [
                'uuid', 'name', 'path', 'directory', 'extension', 'mime_type',
                'size', 'size_bucket', 'modified_at', 'year', 'author', 'title',
                'page_count', 'instance_name', 'folder_label', 'folder_id',
                'ocr_used', 'has_preview',
            ],
        ];

        if (self::SORTS[$sort] ?? null) {
            $options['sort'] = self::SORTS[$sort];
        }

        $result = $this->index->search($query, $options);

        return [
            'hits' => array_map($this->presentHit(...), $result['hits'] ?? []),
            'total' => $result['totalHits'] ?? 0,
            'page' => $result['page'] ?? $page,
            'per_page' => $result['hitsPerPage'] ?? $perPage,
            'total_pages' => $result['totalPages'] ?? 0,
            'facets' => $this->presentFacets($result['facetDistribution'] ?? []),
            'took_ms' => $result['processingTimeMs'] ?? null,
        ];
    }

    /**
     * @param  list<int>|null  $allowedFolderIds  null = administrator, no restriction
     * @param  array<string, list<string>>  $filters
     * @return list<string>
     */
    private function buildFilter(?array $allowedFolderIds, array $filters): array
    {
        $clauses = [];

        if ($allowedFolderIds !== null) {
            $clauses[] = 'folder_id IN ['.implode(', ', $allowedFolderIds).']';
        }

        foreach ($filters as $facet => $values) {
            if (! in_array($facet, self::FACETS, true) || $values === []) {
                continue;
            }

            // Mehrere Werte derselben Facette werden verodert, verschiedene
            // Facetten verundet — so verhalten sich Facettenfilter erwartbar.
            $clauses[] = array_map(
                fn (string $value) => sprintf('%s = %s', $facet, $this->quote($value)),
                array_values($values),
            );
        }

        return $clauses;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function presentHit(array $hit): array
    {
        $formatted = $hit['_formatted'] ?? [];
        unset($hit['_formatted']);

        return $hit + [
            'highlighted_name' => $this->highlight($formatted['name'] ?? ($hit['name'] ?? '')),
            'snippet' => isset($formatted['content'])
                ? $this->highlight($formatted['content'])
                : null,
        ];
    }

    /**
     * Erst alles escapen, dann die Platzhalter zu <mark> machen. Was aus einer
     * indizierten Datei kommt, darf im Browser kein Markup werden.
     */
    private function highlight(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            [self::MARK_OPEN, self::MARK_CLOSE],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }

    /**
     * @param  array<string, array<string, int>>  $distribution
     * @return list<array{name: string, values: list<array{value: string, count: int}>}>
     */
    private function presentFacets(array $distribution): array
    {
        $facets = [];

        foreach (self::FACETS as $facet) {
            $values = $distribution[$facet] ?? [];

            if ($values === []) {
                continue;
            }

            arsort($values);

            $facets[] = [
                'name' => $facet,
                'values' => array_map(
                    fn ($value, $count) => ['value' => (string) $value, 'count' => $count],
                    array_keys($values),
                    array_values($values),
                ),
            ];
        }

        return $facets;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(int $page, int $perPage): array
    {
        return [
            'hits' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'facets' => [],
            'took_ms' => 0,
        ];
    }
}
